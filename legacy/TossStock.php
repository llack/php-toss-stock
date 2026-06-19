<?php

/**
 * 토스증권 Open API PHP 클라이언트 — 레거시 빌드 (PHP 5.2+).
 *
 * 네임스페이스/클로저/composer 없이 동작하는 단일 파일 버전입니다.
 * PHP 5.6 이상이라면 네임스페이스 + composer 를 지원하는 메인 빌드(../src/TossStock.php)를
 * 사용하세요. 이 파일은 5.2~5.5 처럼 그 기능을 못 쓰는 구형 환경 전용입니다.
 *
 * 사용법 (composer 불필요):
 *   require_once 'legacy/TossStock.php';
 *   $toss = new TossStock('c_xxx', 's_xxx');
 *   $price = $toss->getPrices('005930');
 *
 * 메서드/동작은 메인 빌드와 100% 동일하며, 클래스만 전역(네임스페이스 없음)입니다.
 *
 * 구형 환경 주의:
 * - TLS 1.2 강제는 PHP 5.5+ 의 CURL_SSLVERSION_TLSv1_2 가 있을 때만 적용됩니다.
 *   5.2~5.4 에서는 시스템 cURL/OpenSSL 이 협상하는 버전을 따릅니다.
 * - 32비트 PHP 에서 매우 큰 정수(accountSeq 등)는 정밀도 손실이 있을 수 있습니다.
 *   (토스 accountSeq 는 작은 값이라 실사용엔 영향 없음)
 *
 * @see https://openapi.tossinvest.com
 */
class TossStock
{
    const VERSION = '1.0.0-legacy';
    const BASE_URL = 'https://openapi.tossinvest.com';

    private $clientId;
    private $clientSecret;
    private $baseUrl;
    private $timeout;
    private $connectTimeout;
    private $verify;
    private $caBundle;
    private $userAgent;

    private $accessToken;
    private $tokenExpiresAt = 0;

    private $accountSeq;

    private $lastRateLimit;
    private $lastRequestId;

    /** @var array 요청별 응답 헤더 누적 버퍼 (클로저 대체용) */
    private $responseHeaders = array();

    /**
     * @param string $clientId
     * @param string $clientSecret
     * @param array  $options base_url, timeout, connect_timeout, verify, ca_bundle, account_seq, user_agent
     */
    public function __construct($clientId, $clientSecret, $options = array())
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
        if (!is_array($options)) {
            $options = array();
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

    /** OAuth2 액세스 토큰 강제 재발급. */
    public function issueToken()
    {
        $headers = array(
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        );
        $form = $this->encodeForm(array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ));

        $res = $this->http('POST', $this->baseUrl . '/oauth2/token', $headers, $form);
        $status = $res[0]; $respHeaders = $res[1]; $body = $res[2];
        $this->captureRateLimit($respHeaders);
        $decoded = $this->decode($body);

        if ($status >= 200 && $status < 300 && isset($decoded['access_token'])) {
            $this->accessToken    = $decoded['access_token'];
            $expiresIn            = isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 86400;
            $this->tokenExpiresAt = time() + $expiresIn;
            return $decoded;
        }

        $error = isset($decoded['error']) ? $decoded['error'] : 'token-error';
        $desc  = (isset($decoded['error_description']) && $decoded['error_description'] !== '')
            ? $decoded['error_description']
            : ('토큰 발급에 실패했습니다 (HTTP ' . $status . ').');
        throw new TossStockException($desc, $error, $status, null, $decoded, $error);
    }

    /** 유효한 액세스 토큰 반환 (없거나 만료 임박 시 자동 발급). */
    public function getAccessToken()
    {
        if ($this->accessToken === null || time() >= ($this->tokenExpiresAt - 30)) {
            $this->issueToken();
        }
        return $this->accessToken;
    }

    /** 외부 캐시에서 보관한 토큰 주입. */
    public function setAccessToken($accessToken, $expiresIn = null)
    {
        $this->accessToken    = (string) $accessToken;
        $this->tokenExpiresAt = time() + ($expiresIn === null ? 86400 : (int) $expiresIn);
        return $this;
    }

    /** 현재 토큰 정보 반환 (외부 캐시 저장용). */
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

    /** 계좌 목록 조회. */
    public function getAccounts()
    {
        return $this->apiRequest('GET', '/api/v1/accounts');
    }

    /** 이후 계좌 컨텍스트 API 의 기본 계좌(accountSeq) 설정. */
    public function useAccount($accountSeq)
    {
        $this->assertAccountSeq($accountSeq);
        $this->accountSeq = $accountSeq;
        return $this;
    }

    // ------------------------------------------------------------------
    // 시세 (Market Data)
    // ------------------------------------------------------------------

    /** 호가 조회. */
    public function getOrderbook($symbol)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/orderbook', array('symbol' => $symbol));
    }

    /** 현재가 조회 (최대 200건). $symbols: 문자열 또는 배열. */
    public function getPrices($symbols)
    {
        return $this->apiRequest('GET', '/api/v1/prices', array('symbols' => $this->normalizeSymbols($symbols)));
    }

    /** 최근 체결 내역 조회. */
    public function getTrades($symbol, $count = null)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/trades', array('symbol' => $symbol, 'count' => $count));
    }

    /** 상/하한가 조회. */
    public function getPriceLimit($symbol)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/price-limits', array('symbol' => $symbol));
    }

    /** 캔들(OHLCV) 차트 조회. $interval: '1m'|'1d'. $options: count, before, adjusted. */
    public function getCandles($symbol, $interval, $options = array())
    {
        $this->assertSymbol($symbol);
        if (!in_array($interval, array('1m', '1d'), true)) {
            throw new TossStockException("interval 은 '1m' 또는 '1d' 만 허용됩니다.", 'invalid-argument');
        }
        if (!is_array($options)) {
            $options = array();
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

    /** 종목 기본 정보 조회 (최대 200건). */
    public function getStocks($symbols)
    {
        return $this->apiRequest('GET', '/api/v1/stocks', array('symbols' => $this->normalizeSymbols($symbols)));
    }

    /** 매수 유의사항 / VI 발동 정보 조회. */
    public function getStockWarnings($symbol)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/stocks/' . rawurlencode($symbol) . '/warnings');
    }

    // ------------------------------------------------------------------
    // 시장 정보 (Market Info)
    // ------------------------------------------------------------------

    /** 환율 조회 (KRW <-> USD). */
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

    /** 국내(KRX+NXT) 장 운영 정보 조회. */
    public function getKrMarketCalendar($date = null)
    {
        return $this->apiRequest('GET', '/api/v1/market-calendar/KR', array('date' => $date));
    }

    /** 미국 장 운영 정보 조회. */
    public function getUsMarketCalendar($date = null)
    {
        return $this->apiRequest('GET', '/api/v1/market-calendar/US', array('date' => $date));
    }

    // ------------------------------------------------------------------
    // 보유 자산 (Asset)
    // ------------------------------------------------------------------

    /** 보유 주식 조회. */
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

    /** 주문 생성. quantity 또는 orderAmount 중 정확히 하나 지정. */
    public function createOrder($params, $accountSeq = null)
    {
        if (!is_array($params)) {
            throw new TossStockException('주문 파라미터는 배열이어야 합니다.', 'invalid-argument');
        }
        $required = array('symbol', 'side', 'orderType');
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw new TossStockException("주문 필수 항목이 누락되었습니다: " . $field, 'invalid-argument');
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

    /** 주문 정정. KR: quantity 필수, US: 가격만. */
    public function modifyOrder($orderId, $params, $accountSeq = null)
    {
        $this->assertOrderId($orderId);
        if (!is_array($params)) {
            throw new TossStockException('정정 파라미터는 배열이어야 합니다.', 'invalid-argument');
        }
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

    /** 주문 취소. */
    public function cancelOrder($orderId, $accountSeq = null)
    {
        $this->assertOrderId($orderId);
        return $this->apiRequest('POST', '/api/v1/orders/' . rawurlencode($orderId) . '/cancel', array(), null, $accountSeq, true);
    }

    /** 지정가 매수. */
    public function buyLimit($symbol, $quantity, $price, $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'BUY', 'orderType' => 'LIMIT', 'quantity' => $quantity, 'price' => $price,
        ), $extra));
    }

    /** 지정가 매도. */
    public function sellLimit($symbol, $quantity, $price, $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'SELL', 'orderType' => 'LIMIT', 'quantity' => $quantity, 'price' => $price,
        ), $extra));
    }

    /** 시장가 매수 (수량 기준). */
    public function buyMarket($symbol, $quantity, $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'BUY', 'orderType' => 'MARKET', 'quantity' => $quantity,
        ), $extra));
    }

    /** 시장가 매도 (수량 기준). */
    public function sellMarket($symbol, $quantity, $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'SELL', 'orderType' => 'MARKET', 'quantity' => $quantity,
        ), $extra));
    }

    /** 금액 기준 시장가 매수 (미국 주식 전용, 정규장 시간에만). */
    public function buyAmount($symbol, $orderAmount, $extra = array())
    {
        return $this->createOrder($this->mergeOrder(array(
            'symbol' => $symbol, 'side' => 'BUY', 'orderType' => 'MARKET', 'orderAmount' => $orderAmount,
        ), $extra));
    }

    // ------------------------------------------------------------------
    // 주문 내역 (Order History)
    // ------------------------------------------------------------------

    /** 주문 목록 조회. $status: 'OPEN'|'CLOSED'. */
    public function getOrders($status, $options = array(), $accountSeq = null)
    {
        if (!in_array($status, array('OPEN', 'CLOSED'), true)) {
            throw new TossStockException("status 는 'OPEN' 또는 'CLOSED' 만 허용됩니다.", 'invalid-argument');
        }
        if (!is_array($options)) {
            $options = array();
        }
        $query = array('status' => $status);
        if (isset($options['symbol'])) { $this->assertSymbol($options['symbol']); $query['symbol'] = $options['symbol']; }
        if (isset($options['from']))   { $query['from'] = $options['from']; }
        if (isset($options['to']))     { $query['to'] = $options['to']; }
        if (isset($options['cursor'])) { $query['cursor'] = $options['cursor']; }
        if (isset($options['limit']))  { $query['limit'] = (int) $options['limit']; }
        return $this->apiRequest('GET', '/api/v1/orders', $query, null, $accountSeq, true);
    }

    /** 주문 상세 조회 (모든 상태). */
    public function getOrder($orderId, $accountSeq = null)
    {
        $this->assertOrderId($orderId);
        return $this->apiRequest('GET', '/api/v1/orders/' . rawurlencode($orderId), array(), null, $accountSeq, true);
    }

    // ------------------------------------------------------------------
    // 거래 가능 정보 (Order Info)
    // ------------------------------------------------------------------

    /** 매수 가능 금액 조회. $currency: 'KRW'|'USD'. */
    public function getBuyingPower($currency, $accountSeq = null)
    {
        $this->assertCurrency($currency);
        return $this->apiRequest('GET', '/api/v1/buying-power', array('currency' => $currency), null, $accountSeq, true);
    }

    /** 판매 가능 수량 조회. */
    public function getSellableQuantity($symbol, $accountSeq = null)
    {
        $this->assertSymbol($symbol);
        return $this->apiRequest('GET', '/api/v1/sellable-quantity', array('symbol' => $symbol), null, $accountSeq, true);
    }

    /** 시장별 매매 수수료율 조회. */
    public function getCommissions($accountSeq = null)
    {
        return $this->apiRequest('GET', '/api/v1/commissions', array(), null, $accountSeq, true);
    }

    // ------------------------------------------------------------------
    // 메타 정보
    // ------------------------------------------------------------------

    /** 마지막 응답의 Rate Limit 헤더. */
    public function getLastRateLimit()
    {
        return $this->lastRateLimit;
    }

    /** 마지막 응답의 X-Request-Id. */
    public function getLastRequestId()
    {
        return $this->lastRequestId;
    }

    // ==================================================================
    // 내부 구현
    // ==================================================================

    private function apiRequest($method, $path, $query = array(), $body = null, $accountSeq = null, $accountRequired = false)
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

        $res = $this->http($method, $url, $headers, $raw);
        $status = $res[0]; $respHeaders = $res[1]; $rawBody = $res[2];
        $this->captureRateLimit($respHeaders);
        $this->lastRequestId = isset($respHeaders['x-request-id']) ? $respHeaders['x-request-id'] : null;

        $decoded = $this->decode($rawBody);
        if ($status >= 200 && $status < 300) {
            return (is_array($decoded) && array_key_exists('result', $decoded)) ? $decoded['result'] : $decoded;
        }
        $this->throwApiError($status, $decoded, $respHeaders);
    }

    /**
     * 저수준 HTTP 요청 (cURL). 보안 기본값(TLS 검증, HTTPS 강제, 리다이렉트 차단) 적용.
     * 가능한 환경에서는 TLS 1.2 를 강제합니다.
     *
     * @return array array(int status, array headers(lowercased), string body)
     */
    private function http($method, $url, $headers, $bodyRaw)
    {
        $ch = curl_init();
        $this->responseHeaders = array();

        $opts = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verify,
            CURLOPT_SSL_VERIFYHOST => $this->verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HEADERFUNCTION => array($this, 'curlHeaderCallback'),
        );

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
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

        return array($status, $this->responseHeaders, $body);
    }

    /**
     * cURL 헤더 콜백 (클로저 미지원 환경용 메서드 콜백). cURL 이 호출하므로 public 이어야 합니다.
     *
     * @return int 처리한 바이트 수
     */
    public function curlHeaderCallback($ch, $line)
    {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $this->responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return strlen($line);
    }

    /** 쿼리스트링 생성 (null 제외, RFC3986 인코딩). PHP_QUERY_RFC3986 미사용. */
    private function buildQuery($query)
    {
        if (!is_array($query)) {
            return '';
        }
        $pairs = array();
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $pairs);
    }

    /** application/x-www-form-urlencoded 본문 생성 (RFC3986). */
    private function encodeForm($params)
    {
        $pairs = array();
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $pairs);
    }

    /** 응답 본문 JSON 디코드. (구형 호환을 위해 옵션 인자 미사용) */
    private function decode($raw)
    {
        if ($raw === '' || $raw === null) {
            return array();
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && strtolower(trim($raw)) !== 'null') {
            throw new TossStockException('응답 JSON 파싱에 실패했습니다.', 'invalid-response', 0, null, array('raw' => substr($raw, 0, 500)));
        }
        return $decoded;
    }

    private function captureRateLimit($headers)
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

    private function throwApiError($status, $decoded, $headers)
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
            $desc = (isset($decoded['error_description']) && $decoded['error_description'] !== '')
                ? $decoded['error_description']
                : ('요청에 실패했습니다 (HTTP ' . $status . ').');
            throw new TossStockException($desc, $error, $status, null, $decoded, $error);
        }
        $requestId = isset($headers['x-request-id']) ? $headers['x-request-id'] : null;
        throw new TossStockException('요청에 실패했습니다 (HTTP ' . $status . ').', 'http-error', $status, $requestId);
    }

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
        // 정수/숫자 문자열만 허용하며 양수여야 함(0/음수/부동소수/불리언 거부). 문자열 정규식으로 부호까지 검증.
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

    /** decimal 필드를 API 기대 문자열로 변환 (정밀도 보존). */
    private function decimalString($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $s = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
            return $s === '' ? '0' : $s;
        }
        throw new TossStockException('수량/가격/금액은 문자열 또는 숫자여야 합니다.', 'invalid-argument');
    }

    /** 주문 본문을 화이트리스트 키로만 구성. */
    private function buildOrderPayload($params)
    {
        $payload = array();
        $passthrough = array('clientOrderId', 'symbol', 'side', 'orderType', 'timeInForce');
        foreach ($passthrough as $key) {
            if (isset($params[$key]) && $params[$key] !== null) {
                $payload[$key] = $params[$key];
            }
        }
        $decimals = array('quantity', 'price', 'orderAmount');
        foreach ($decimals as $key) {
            if (isset($params[$key]) && $params[$key] !== null && $params[$key] !== '') {
                $payload[$key] = $this->decimalString($params[$key]);
            }
        }
        if (isset($params['confirmHighValueOrder'])) {
            $payload['confirmHighValueOrder'] = (bool) $params['confirmHighValueOrder'];
        }
        return $payload;
    }

    private function mergeOrder($base, $extra)
    {
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

/**
 * 토스증권 API 클라이언트 예외 (레거시 빌드).
 */
class TossStockException extends Exception
{
    private $errorCode;
    private $httpStatus;
    private $requestId;
    private $data;
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

    public function getErrorCode()  { return $this->errorCode; }
    public function getHttpStatus() { return $this->httpStatus; }
    public function getRequestId()  { return $this->requestId; }
    public function getData()       { return $this->data; }
    public function getOAuthError() { return $this->oauthError; }
}
