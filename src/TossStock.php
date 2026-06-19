<?php

namespace Llack\TossStock;

/**
 * 토스증권 Open API PHP 클라이언트.
 *
 * OAuth 2.0 Client Credentials 방식으로 client_id / client_secret 두 개만으로 초기화하며,
 * 액세스 토큰의 발급·캐시·만료 재발급을 내부에서 자동으로 처리합니다.
 *
 *   $toss = new \Llack\TossStock\TossStock('c_xxx', 's_xxx');
 *   $price = $toss->getPrices('005930');
 *   $toss->useAccount($toss->getAccounts()[0]['accountSeq']);
 *   $order = $toss->buyLimit('005930', 10, '70000');
 *
 * PHP 5.6 이상에서 동작하며 (PHP 8.x 포함), 추가 의존성은 ext-curl / ext-json 뿐입니다.
 *
 * @see https://openapi.tossinvest.com  토스증권 Open API
 */
class TossStock
{
    const VERSION = '1.0.0';
    const BASE_URL = 'https://openapi.tossinvest.com';

    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var string */
    private $baseUrl;
    /** @var int 요청 타임아웃(초) */
    private $timeout;
    /** @var int 연결 타임아웃(초) */
    private $connectTimeout;
    /** @var bool TLS 인증서 검증 여부 (기본 true, 끄지 않기를 권장) */
    private $verify;
    /** @var string|null 사용자 지정 CA 번들 경로 */
    private $caBundle;
    /** @var string */
    private $userAgent;

    /** @var string|null 현재 액세스 토큰 */
    private $accessToken;
    /** @var int 토큰 만료 시각(unix timestamp) */
    private $tokenExpiresAt = 0;

    /** @var int|string|null 기본 계좌 seq (X-Tossinvest-Account) */
    private $accountSeq;

    /** @var array|null 마지막 응답의 Rate Limit 헤더 */
    private $lastRateLimit;
    /** @var string|null 마지막 응답의 X-Request-Id */
    private $lastRequestId;

    /**
     * @param string $clientId     발급받은 client_id
     * @param string $clientSecret 발급받은 client_secret
     * @param array  $options      base_url, timeout, connect_timeout, verify, ca_bundle, account_seq, user_agent
     *
     * @throws TossStockException
     */
    public function __construct($clientId, $clientSecret, array $options = array())
    {
        if (!extension_loaded('curl')) {
            throw new TossStockException('cURL 확장이 필요합니다 (ext-curl).', 'missing-extension');
        }
        if (!extension_loaded('json')) {
            throw new TossStockException('JSON 확장이 필요합니다 (ext-json).', 'missing-extension');
        }
        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            throw new TossStockException('client_id 와 client_secret 이 필요합니다.', 'invalid-credentials');
        }

        $this->clientId       = $clientId;
        $this->clientSecret   = $clientSecret;
        $this->baseUrl        = isset($options['base_url']) ? rtrim($options['base_url'], '/') : self::BASE_URL;
        $this->timeout        = isset($options['timeout']) ? (int) $options['timeout'] : 10;
        $this->connectTimeout = isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 5;
        $this->verify         = isset($options['verify']) ? (bool) $options['verify'] : true;
        $this->caBundle       = isset($options['ca_bundle']) ? $options['ca_bundle'] : null;
        $this->userAgent      = isset($options['user_agent']) ? $options['user_agent'] : 'php-toss-stock/' . self::VERSION;
        $this->accountSeq     = isset($options['account_seq']) ? $options['account_seq'] : null;
    }

    // ------------------------------------------------------------------
    // 인증 (Auth)
    // ------------------------------------------------------------------

    /**
     * OAuth2 액세스 토큰을 강제로 재발급합니다. (만료 시 자동 호출되므로 직접 부를 필요는 거의 없습니다.)
     *
     * @return array {access_token, token_type, expires_in}
     * @throws TossStockException
     */
    public function issueToken()
    {
        $headers = array(
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        );
        $form = http_build_query(array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ), '', '&', PHP_QUERY_RFC3986);

        list($status, $respHeaders, $body) = $this->http('POST', $this->baseUrl . '/oauth2/token', $headers, $form);
        $this->captureRateLimit($respHeaders);
        $decoded = $this->decode($body);

        if ($status >= 200 && $status < 300 && isset($decoded['access_token'])) {
            $this->accessToken    = $decoded['access_token'];
            $expiresIn            = isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 86400;
            $this->tokenExpiresAt = time() + $expiresIn;
            return $decoded;
        }

        // 토큰 엔드포인트는 OAuth2 표준 에러 포맷({error, error_description})을 사용합니다.
        $error = isset($decoded['error']) ? $decoded['error'] : 'token-error';
        $desc  = isset($decoded['error_description']) && $decoded['error_description'] !== ''
            ? $decoded['error_description']
            : ('토큰 발급에 실패했습니다 (HTTP ' . $status . ').');
        throw new TossStockException($desc, $error, $status, null, $decoded, $error);
    }

    /**
     * 유효한 액세스 토큰을 반환합니다. 없거나 만료가 임박했으면 자동 발급합니다.
     *
     * @return string
     * @throws TossStockException
     */
    public function getAccessToken()
    {
        if ($this->accessToken === null || time() >= ($this->tokenExpiresAt - 30)) {
            $this->issueToken();
        }
        return $this->accessToken;
    }

    /**
     * 외부 캐시(예: 서버리스 환경)에서 보관해 둔 토큰을 주입합니다.
     *
     * @param string   $accessToken
     * @param int|null $expiresIn 남은 만료 초. null 이면 기본 86400.
     * @return $this
     */
    public function setAccessToken($accessToken, $expiresIn = null)
    {
        $this->accessToken    = (string) $accessToken;
        $this->tokenExpiresAt = time() + ($expiresIn === null ? 86400 : (int) $expiresIn);
        return $this;
    }

    /**
     * 현재 토큰 정보를 반환합니다. (외부 캐시 저장용)
     *
     * @return array {access_token, expires_at}
     */
    public function getTokenInfo()
    {
        return array(
            'access_token' => $this->accessToken,
            'expires_at'   => $this->tokenExpiresAt,
        );
    }

    // ------------------------------------------------------------------
    // 계좌 (Account)
    // ------------------------------------------------------------------

    /**
     * 사용자의 계좌 목록을 조회합니다.
     *
     * @return array Account[]
     * @throws TossStockException
     */
    public function getAccounts()
    {
        return $this->apiRequest('GET', '/api/v1/accounts');
    }

    /**
     * 이후 계좌 컨텍스트 API 호출에 사용할 기본 계좌(accountSeq)를 설정합니다.
     *
     * @param int|string $accountSeq
     * @return $this
     */
    public function useAccount($accountSeq)
    {
        $this->assertAccountSeq($accountSeq);
        $this->accountSeq = $accountSeq;
        return $this;
    }

    // ------------------------------------------------------------------
    // 시세 (Market Data)
    // ------------------------------------------------------------------

    /**
     * 호가 조회.
     *
     * @param string $symbol
     * @return array
     */
    public function getOrderbook($symbol)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/orderbook', array('symbol' => $symbol));
    }

    /**
     * 현재가 조회 (최대 200건 다건 조회).
     *
     * @param string|array $symbols '005930,000660' 또는 array('005930','000660')
     * @return array PriceResponse[]
     */
    public function getPrices($symbols)
    {
        return $this->apiRequest('GET', '/api/v1/prices', array('symbols' => $this->normalizeSymbols($symbols)));
    }

    /**
     * 최근 체결 내역 조회.
     *
     * @param string   $symbol
     * @param int|null $count 최대 50
     * @return array Trade[]
     */
    public function getTrades($symbol, $count = null)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/trades', array('symbol' => $symbol, 'count' => $count));
    }

    /**
     * 상/하한가 조회.
     *
     * @param string $symbol
     * @return array
     */
    public function getPriceLimit($symbol)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/price-limits', array('symbol' => $symbol));
    }

    /**
     * 캔들(OHLCV) 차트 조회.
     *
     * @param string $symbol
     * @param string $interval '1m' | '1d'
     * @param array  $options  count(<=200), before(ISO8601), adjusted(bool)
     * @return array CandlePageResponse {candles, nextBefore}
     */
    public function getCandles($symbol, $interval, array $options = array())
    {
        $this->assertSymbol($symbol);
        if (!in_array($interval, array('1m', '1d'), true)) {
            throw new TossStockException("interval 은 '1m' 또는 '1d' 만 허용됩니다.", 'invalid-argument');
        }
        $query = array('symbol' => $symbol, 'interval' => $interval);
        if (isset($options['count']))    { $query['count'] = (int) $options['count']; }
        if (isset($options['before']))   { $query['before'] = $options['before']; }
        if (isset($options['adjusted'])) { $query['adjusted'] = $options['adjusted'] ? 'true' : 'false'; }
        return $this->apiRequest('GET', '/api/v1/candles', $query);
    }

    // ------------------------------------------------------------------
    // 종목 정보 (Stock Info)
    // ------------------------------------------------------------------

    /**
     * 종목 기본 정보 조회 (최대 200건 다건 조회).
     *
     * @param string|array $symbols
     * @return array StockInfo[]
     */
    public function getStocks($symbols)
    {
        return $this->apiRequest('GET', '/api/v1/stocks', array('symbols' => $this->normalizeSymbols($symbols)));
    }

    /**
     * 매수 유의사항 / VI 발동 정보 조회.
     *
     * @param string $symbol
     * @return array StockWarning[]
     */
    public function getStockWarnings($symbol)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/stocks/' . rawurlencode($symbol) . '/warnings');
    }

    // ------------------------------------------------------------------
    // 시장 정보 (Market Info)
    // ------------------------------------------------------------------

    /**
     * 환율 조회 (KRW <-> USD).
     *
     * @param string      $baseCurrency  'KRW' | 'USD'
     * @param string      $quoteCurrency 'KRW' | 'USD'
     * @param string|null $dateTime      특정 시점 환율 (ISO8601). null 이면 현재 유효 환율.
     * @return array ExchangeRateResponse
     */
    public function getExchangeRate($baseCurrency, $quoteCurrency, $dateTime = null)
    {
        $this->assertCurrency($baseCurrency);
        $this->assertCurrency($quoteCurrency);
        return $this->apiRequest('GET', '/api/v1/exchange-rate', array(
            'baseCurrency'  => $baseCurrency,
            'quoteCurrency' => $quoteCurrency,
            'dateTime'      => $dateTime,
        ));
    }

    /**
     * 국내(KRX+NXT) 장 운영 정보 조회.
     *
     * @param string|null $date YYYY-MM-DD
     * @return array KrMarketCalendarResponse
     */
    public function getKrMarketCalendar($date = null)
    {
        return $this->apiRequest('GET', '/api/v1/market-calendar/KR', array('date' => $date));
    }

    /**
     * 미국 장 운영 정보 조회.
     *
     * @param string|null $date YYYY-MM-DD (미국 현지 날짜)
     * @return array UsMarketCalendarResponse
     */
    public function getUsMarketCalendar($date = null)
    {
        return $this->apiRequest('GET', '/api/v1/market-calendar/US', array('date' => $date));
    }

    // ------------------------------------------------------------------
    // 보유 자산 (Asset)
    // ------------------------------------------------------------------

    /**
     * 보유 주식 조회.
     *
     * @param string|null    $symbol     특정 종목만 필터링 (선택)
     * @param int|string|null $accountSeq 기본 계좌를 덮어쓸 계좌 (선택)
     * @return array HoldingsOverview
     */
    public function getHoldings($symbol = null, $accountSeq = null)
    {
        $query = array();
        if ($symbol !== null) {
            $this->assertSymbol($symbol);
            $query['symbol'] = $symbol;
        }
        return $this->apiRequest('GET', '/api/v1/holdings', $query, null, $accountSeq, true);
    }

    // ------------------------------------------------------------------
    // 주문 (Order)
    // ------------------------------------------------------------------

    /**
     * 주문 생성. quantity 또는 orderAmount 중 정확히 하나를 지정합니다.
     *
     * @param array            $params     symbol, side, orderType, quantity|orderAmount, price?, timeInForce?, clientOrderId?, confirmHighValueOrder?
     * @param int|string|null  $accountSeq 기본 계좌를 덮어쓸 계좌 (선택)
     * @return array OrderResponse {orderId, clientOrderId}
     * @throws TossStockException
     */
    public function createOrder(array $params, $accountSeq = null)
    {
        foreach (array('symbol', 'side', 'orderType') as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw new TossStockException("주문 필수 항목이 누락되었습니다: {$field}", 'invalid-argument');
            }
        }
        $this->assertSymbol($params['symbol']);
        if (!in_array($params['side'], array('BUY', 'SELL'), true)) {
            throw new TossStockException("side 는 'BUY' 또는 'SELL' 만 허용됩니다.", 'invalid-argument');
        }
        if (!in_array($params['orderType'], array('LIMIT', 'MARKET'), true)) {
            throw new TossStockException("orderType 은 'LIMIT' 또는 'MARKET' 만 허용됩니다.", 'invalid-argument');
        }

        $hasQty = isset($params['quantity']) && $params['quantity'] !== null && $params['quantity'] !== '';
        $hasAmt = isset($params['orderAmount']) && $params['orderAmount'] !== null && $params['orderAmount'] !== '';
        if ($hasQty === $hasAmt) {
            throw new TossStockException('quantity 또는 orderAmount 중 정확히 하나만 지정해야 합니다.', 'invalid-argument');
        }
        if ($params['orderType'] === 'LIMIT' && (!isset($params['price']) || $params['price'] === '' || $params['price'] === null)) {
            throw new TossStockException('지정가(LIMIT) 주문에는 price 가 필요합니다.', 'invalid-argument');
        }
        if (isset($params['clientOrderId'])) {
            $this->assertClientOrderId($params['clientOrderId']);
        }

        return $this->apiRequest('POST', '/api/v1/orders', array(), $this->buildOrderPayload($params), $accountSeq, true);
    }

    /**
     * 주문 정정 (가격/수량 변경). KR: quantity 필수, US: quantity 전달 불가(가격만).
     *
     * @param string           $orderId
     * @param array            $params     orderType, price?, quantity?, confirmHighValueOrder?
     * @param int|string|null  $accountSeq
     * @return array OrderOperationResponse {orderId}
     */
    public function modifyOrder($orderId, array $params, $accountSeq = null)
    {
        $this->assertOrderId($orderId);
        if (!isset($params['orderType']) || !in_array($params['orderType'], array('LIMIT', 'MARKET'), true)) {
            throw new TossStockException("정정 시 orderType('LIMIT'|'MARKET')이 필요합니다.", 'invalid-argument');
        }
        if ($params['orderType'] === 'LIMIT' && (!isset($params['price']) || $params['price'] === '' || $params['price'] === null)) {
            throw new TossStockException('지정가(LIMIT) 정정에는 price 가 필요합니다.', 'invalid-argument');
        }

        $payload = array('orderType' => $params['orderType']);
        if (isset($params['quantity']) && $params['quantity'] !== null && $params['quantity'] !== '') {
            $payload['quantity'] = $this->decimalString($params['quantity']);
        }
        if (isset($params['price']) && $params['price'] !== null && $params['price'] !== '') {
            $payload['price'] = $this->decimalString($params['price']);
        }
        if (isset($params['confirmHighValueOrder'])) {
            $payload['confirmHighValueOrder'] = (bool) $params['confirmHighValueOrder'];
        }

        return $this->apiRequest('POST', '/api/v1/orders/' . rawurlencode($orderId) . '/modify', array(), $payload, $accountSeq, true);
    }

    /**
     * 주문 취소. 이미 체결된 주문은 취소할 수 없습니다.
     *
     * @param string           $orderId
     * @param int|string|null  $accountSeq
     * @return array OrderOperationResponse {orderId}
     */
    public function cancelOrder($orderId, $accountSeq = null)
    {
        $this->assertOrderId($orderId);
        return $this->apiRequest('POST', '/api/v1/orders/' . rawurlencode($orderId) . '/cancel', array(), null, $accountSeq, true);
    }

    // -- 주문 편의 헬퍼 ------------------------------------------------

    /** 지정가 매수. */
    public function buyLimit($symbol, $quantity, $price, array $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'BUY', 'orderType' => 'LIMIT', 'quantity' => $quantity, 'price' => $price,
        ), $extra));
    }

    /** 지정가 매도. */
    public function sellLimit($symbol, $quantity, $price, array $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'SELL', 'orderType' => 'LIMIT', 'quantity' => $quantity, 'price' => $price,
        ), $extra));
    }

    /** 시장가 매수 (수량 기준). */
    public function buyMarket($symbol, $quantity, array $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'BUY', 'orderType' => 'MARKET', 'quantity' => $quantity,
        ), $extra));
    }

    /** 시장가 매도 (수량 기준). */
    public function sellMarket($symbol, $quantity, array $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'SELL', 'orderType' => 'MARKET', 'quantity' => $quantity,
        ), $extra));
    }

    /** 금액 기준 시장가 매수 (미국 주식 전용, 정규장 시간에만 가능). */
    public function buyAmount($symbol, $orderAmount, array $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'BUY', 'orderType' => 'MARKET', 'orderAmount' => $orderAmount,
        ), $extra));
    }

    // ------------------------------------------------------------------
    // 주문 내역 (Order History)
    // ------------------------------------------------------------------

    /**
     * 주문 목록 조회.
     *
     * @param string           $status     'OPEN' | 'CLOSED'
     * @param array            $options    symbol, from(YYYY-MM-DD), to, cursor, limit(<=100)
     * @param int|string|null  $accountSeq
     * @return array PaginatedOrderResponse {orders, nextCursor, hasNext}
     */
    public function getOrders($status, array $options = array(), $accountSeq = null)
    {
        if (!in_array($status, array('OPEN', 'CLOSED'), true)) {
            throw new TossStockException("status 는 'OPEN' 또는 'CLOSED' 만 허용됩니다.", 'invalid-argument');
        }
        $query = array('status' => $status);
        if (isset($options['symbol'])) { $this->assertSymbol($options['symbol']); $query['symbol'] = $options['symbol']; }
        if (isset($options['from']))   { $query['from'] = $options['from']; }
        if (isset($options['to']))     { $query['to'] = $options['to']; }
        if (isset($options['cursor'])) { $query['cursor'] = $options['cursor']; }
        if (isset($options['limit']))  { $query['limit'] = (int) $options['limit']; }
        return $this->apiRequest('GET', '/api/v1/orders', $query, null, $accountSeq, true);
    }

    /**
     * 주문 상세 조회 (모든 상태).
     *
     * @param string           $orderId
     * @param int|string|null  $accountSeq
     * @return array Order
     */
    public function getOrder($orderId, $accountSeq = null)
    {
        $this->assertOrderId($orderId);
        return $this->apiRequest('GET', '/api/v1/orders/' . rawurlencode($orderId), array(), null, $accountSeq, true);
    }

    // ------------------------------------------------------------------
    // 거래 가능 정보 (Order Info)
    // ------------------------------------------------------------------

    /**
     * 매수 가능 금액 조회 (현금 기준, 미수 미발생).
     *
     * @param string           $currency 'KRW' | 'USD'
     * @param int|string|null  $accountSeq
     * @return array BuyingPowerResponse
     */
    public function getBuyingPower($currency, $accountSeq = null)
    {
        $this->assertCurrency($currency);
        return $this->apiRequest('GET', '/api/v1/buying-power', array('currency' => $currency), null, $accountSeq, true);
    }

    /**
     * 판매 가능 수량 조회.
     *
     * @param string           $symbol
     * @param int|string|null  $accountSeq
     * @return array SellableQuantityResponse
     */
    public function getSellableQuantity($symbol, $accountSeq = null)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/sellable-quantity', array('symbol' => $symbol), null, $accountSeq, true);
    }

    /**
     * 시장별 매매 수수료율 조회.
     *
     * @param int|string|null $accountSeq
     * @return array Commission[]
     */
    public function getCommissions($accountSeq = null)
    {
        return $this->apiRequest('GET', '/api/v1/commissions', array(), null, $accountSeq, true);
    }

    // ------------------------------------------------------------------
    // 메타 정보
    // ------------------------------------------------------------------

    /**
     * 마지막 응답의 Rate Limit 헤더를 반환합니다.
     *
     * @return array|null {limit, remaining, reset, retryAfter}
     */
    public function getLastRateLimit()
    {
        return $this->lastRateLimit;
    }

    /**
     * 마지막 응답의 X-Request-Id (CS 문의 시 첨부 권장).
     *
     * @return string|null
     */
    public function getLastRequestId()
    {
        return $this->lastRequestId;
    }

    // ==================================================================
    // 내부 구현
    // ==================================================================

    /**
     * 인증이 필요한 BFF API 요청을 보냅니다. 성공 시 envelope 의 result 페이로드를 반환합니다.
     *
     * @return mixed
     * @throws TossStockException
     */
    private function apiRequest($method, $path, array $query = array(), $body = null, $accountSeq = null, $accountRequired = false)
    {
        // 계좌 검증을 토큰 발급보다 먼저 — 누락 시 불필요한 네트워크 호출 방지
        $accountHeader = null;
        if ($accountRequired) {
            $seq = $accountSeq !== null ? $accountSeq : $this->accountSeq;
            if ($seq === null) {
                throw new TossStockException(
                    '계좌가 필요합니다. useAccount() 로 기본 계좌를 설정하거나 메서드 인자로 accountSeq 를 전달하세요.',
                    'account-required'
                );
            }
            $this->assertAccountSeq($seq);
            // 검증된 값(정수 또는 숫자 문자열)을 그대로 사용 — int64 오버플로 방지
            $accountHeader = 'X-Tossinvest-Account: ' . $seq;
        }

        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->getAccessToken(),
        );
        if ($accountHeader !== null) {
            $headers[] = $accountHeader;
        }

        $raw = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $raw = json_encode($body);
        }

        $url = $this->baseUrl . $path;
        $qs  = $this->buildQuery($query);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }

        list($status, $respHeaders, $rawBody) = $this->http($method, $url, $headers, $raw);
        $this->captureRateLimit($respHeaders);
        $this->lastRequestId = isset($respHeaders['x-request-id']) ? $respHeaders['x-request-id'] : null;

        $decoded = $this->decode($rawBody);
        if ($status >= 200 && $status < 300) {
            return (is_array($decoded) && array_key_exists('result', $decoded)) ? $decoded['result'] : $decoded;
        }
        $this->throwApiError($status, $decoded, $respHeaders);
    }

    /**
     * 저수준 HTTP 요청 (cURL). 보안 기본값(TLS 검증, TLS1.2+, HTTPS 강제, 리다이렉트 차단)을 적용합니다.
     *
     * @return array [int status, array headers(lowercased), string body]
     * @throws TossStockException
     */
    private function http($method, $url, array $headers, $bodyRaw)
    {
        $ch          = curl_init();
        $respHeaders = array();

        $opts = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verify,
            CURLOPT_SSL_VERIFYHOST => $this->verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '', // gzip/deflate 자동 협상
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        );

        // HTTPS 프로토콜로만 통신 (다른 스킴/리다이렉트 차단)
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        // 최소 TLS 1.2 강제 (지원되는 환경에서)
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $opts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }
        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $bodyRaw === null ? '' : $bodyRaw;
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($bodyRaw !== null) {
                $opts[CURLOPT_POSTFIELDS] = $bodyRaw;
            }
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);

        if ($body === false) {
            $err   = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new TossStockException('네트워크 오류: ' . $err, 'network-error', 0, null, array('curl_errno' => $errno));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array($status, $respHeaders, $body);
    }

    /**
     * 쿼리스트링을 만듭니다. null 값은 제외하고 RFC3986 인코딩을 사용합니다.
     *
     * @return string
     */
    private function buildQuery(array $query)
    {
        $clean = array();
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $clean[$key] = $value;
        }
        if (!$clean) {
            return '';
        }
        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 응답 본문을 JSON 디코드합니다. 큰 정수는 문자열로 보존합니다.
     *
     * @return mixed
     * @throws TossStockException
     */
    private function decode($raw)
    {
        if ($raw === '' || $raw === null) {
            return array();
        }
        $flags   = defined('JSON_BIGINT_AS_STRING') ? JSON_BIGINT_AS_STRING : 0;
        $decoded = json_decode($raw, true, 512, $flags);
        if ($decoded === null && strtolower(trim($raw)) !== 'null') {
            throw new TossStockException('응답 JSON 파싱에 실패했습니다.', 'invalid-response', 0, null, array('raw' => substr($raw, 0, 500)));
        }
        return $decoded;
    }

    /**
     * Rate Limit 헤더를 보관합니다.
     */
    private function captureRateLimit(array $headers)
    {
        $map = array(
            'x-ratelimit-limit'     => 'limit',
            'x-ratelimit-remaining' => 'remaining',
            'x-ratelimit-reset'     => 'reset',
            'retry-after'           => 'retryAfter',
        );
        $rl = array();
        foreach ($map as $headerKey => $outKey) {
            if (isset($headers[$headerKey])) {
                $rl[$outKey] = $headers[$headerKey];
            }
        }
        if ($rl) {
            $this->lastRateLimit = $rl;
        }
    }

    /**
     * BFF 에러 응답을 예외로 변환해 던집니다.
     *
     * @throws TossStockException
     * @return void
     */
    private function throwApiError($status, $decoded, array $headers)
    {
        if (is_array($decoded) && isset($decoded['error'])) {
            $error = $decoded['error'];
            if (is_array($error)) {
                $code      = isset($error['code']) ? $error['code'] : 'unknown-error';
                $message   = (isset($error['message']) && $error['message'] !== '') ? $error['message'] : ('요청에 실패했습니다 (HTTP ' . $status . ').');
                $requestId = isset($error['requestId']) ? $error['requestId'] : (isset($headers['x-request-id']) ? $headers['x-request-id'] : null);
                $data      = isset($error['data']) ? $error['data'] : null;
                throw new TossStockException($message, $code, $status, $requestId, $data);
            }
            // OAuth2 표준 에러 포맷
            $desc = isset($decoded['error_description']) && $decoded['error_description'] !== ''
                ? $decoded['error_description']
                : ('요청에 실패했습니다 (HTTP ' . $status . ').');
            throw new TossStockException($desc, $error, $status, null, $decoded, $error);
        }
        $requestId = isset($headers['x-request-id']) ? $headers['x-request-id'] : null;
        throw new TossStockException('요청에 실패했습니다 (HTTP ' . $status . ').', 'http-error', $status, $requestId);
    }

    // -- 입력 검증 / 정규화 --------------------------------------------

    private function assertSymbol($symbol, $allowComma = false)
    {
        $pattern = $allowComma ? '/^[A-Za-z0-9.,\-]+$/' : '/^[A-Za-z0-9.\-]+$/';
        if (!is_string($symbol) || !preg_match($pattern, $symbol)) {
            throw new TossStockException('종목 심볼 형식이 올바르지 않습니다.', 'invalid-argument');
        }
    }

    private function normalizeSymbols($symbols)
    {
        if (is_array($symbols)) {
            $symbols = implode(',', $symbols);
        }
        $this->assertSymbol($symbols, true);
        return $symbols;
    }

    private function assertCurrency($currency)
    {
        if (!in_array($currency, array('KRW', 'USD'), true)) {
            throw new TossStockException("통화는 'KRW' 또는 'USD' 만 허용됩니다.", 'invalid-argument');
        }
    }

    private function assertOrderId($orderId)
    {
        if (!is_string($orderId) || $orderId === '') {
            throw new TossStockException('orderId 가 필요합니다.', 'invalid-argument');
        }
    }

    private function assertAccountSeq($accountSeq)
    {
        // 정수 또는 숫자 문자열만 허용하며 양수여야 함(0/음수/부동소수/불리언 거부).
        // 문자열 기반 정규식 검사로 int64 오버플로 없이 부호까지 검증한다.
        if ((!is_int($accountSeq) && !is_string($accountSeq)) || !preg_match('/^[1-9][0-9]*$/', (string) $accountSeq)) {
            throw new TossStockException('accountSeq 는 양의 정수여야 합니다.', 'invalid-argument');
        }
    }

    private function assertClientOrderId($clientOrderId)
    {
        if (!is_string($clientOrderId) || !preg_match('/^[a-zA-Z0-9\-_]{1,36}$/', $clientOrderId)) {
            throw new TossStockException('clientOrderId 는 영숫자/-/_ 36자 이내여야 합니다.', 'invalid-argument');
        }
    }

    /**
     * decimal 필드를 API 가 기대하는 문자열로 변환합니다 (정밀도 보존).
     *
     * @param string|int|float $value
     * @return string
     */
    private function decimalString($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // 로케일 독립(%F) + 불필요한 0/소수점 제거
            $s = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
            return $s === '' ? '0' : $s;
        }
        throw new TossStockException('수량/가격/금액은 문자열 또는 숫자여야 합니다.', 'invalid-argument');
    }

    /**
     * 주문 본문을 화이트리스트 키로만 구성합니다 (불필요한 필드/누수 방지).
     *
     * @return array
     */
    private function buildOrderPayload(array $params)
    {
        $payload = array();
        foreach (array('clientOrderId', 'symbol', 'side', 'orderType', 'timeInForce') as $key) {
            if (isset($params[$key]) && $params[$key] !== null) {
                $payload[$key] = $params[$key];
            }
        }
        foreach (array('quantity', 'price', 'orderAmount') as $key) {
            if (isset($params[$key]) && $params[$key] !== null && $params[$key] !== '') {
                $payload[$key] = $this->decimalString($params[$key]);
            }
        }
        if (isset($params['confirmHighValueOrder'])) {
            $payload['confirmHighValueOrder'] = (bool) $params['confirmHighValueOrder'];
        }
        return $payload;
    }

    private function mergeOrder(array $base, array $extra)
    {
        foreach ($extra as $key => $value) {
            $base[$key] = $value;
        }
        return $base;
    }
}

/**
 * 토스증권 API 클라이언트가 던지는 예외.
 *
 * - getErrorCode(): API 에러 코드(flat string) 또는 OAuth error.
 * - getHttpStatus(): HTTP 상태 코드.
 * - getRequestId(): CS 문의용 요청 식별자.
 * - getData(): 에러 해결 힌트(필드/제약/한도 등). 없으면 null.
 * - getOAuthError(): 토큰 발급 실패 시 OAuth2 표준 error 코드.
 */
class TossStockException extends \Exception
{
    /** @var string|null */
    private $errorCode;
    /** @var int */
    private $httpStatus;
    /** @var string|null */
    private $requestId;
    /** @var mixed */
    private $data;
    /** @var string|null */
    private $oauthError;

    public function __construct($message, $errorCode = null, $httpStatus = 0, $requestId = null, $data = null, $oauthError = null)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $this->requestId  = $requestId;
        $this->data       = $data;
        $this->oauthError = $oauthError;
    }

    /** @return string|null */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** @return int */
    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /** @return string|null */
    public function getRequestId()
    {
        return $this->requestId;
    }

    /** @return mixed */
    public function getData()
    {
        return $this->data;
    }

    /** @return string|null */
    public function getOAuthError()
    {
        return $this->oauthError;
    }
}
