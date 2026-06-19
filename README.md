# php-toss-stock

토스증권 Open API 를 위한 가볍고 의존성 없는 PHP 클라이언트.
`client_id` / `client_secret` 두 개로 초기화하면 토큰 발급·갱신부터 모든 엔드포인트 호출까지 알아서 처리합니다.

![PHP](https://img.shields.io/badge/PHP-5.6%20~%208.x-777BB4)
![Legacy](https://img.shields.io/badge/legacy-PHP%205.2%2B-999)
![License](https://img.shields.io/badge/license-MIT-blue)

> ⚠️ **비공식(unofficial) 라이브러리입니다.** 토스증권과 제휴·관련이 없습니다. 주문 API 는 실제 자산을 거래하며, 사용에 따른 모든 책임은 사용자에게 있습니다.

## 특징

- 단일 클래스 · 추가 의존성은 `ext-curl` / `ext-json` 뿐
- OAuth2 액세스 토큰 **자동 발급 · 캐시 · 만료 재발급**
- 시세 · 종목 · 시장 · 계좌 · 자산 · 주문 · 주문내역 · 거래정보 **전 엔드포인트 지원**
- 기본값부터 보안 우선: **TLS 인증서 검증 · TLS 1.2 강제 · HTTPS 전용 · 리다이렉트 차단 · 입력 검증**
- **PHP 5.6 ~ 8.x** 지원 (`src/`), 더 구형은 **레거시 빌드 PHP 5.2+** (`legacy/`)

## 설치

### Composer (PHP 5.6+)

```bash
composer require llack/php-toss-stock
```

```php
require 'vendor/autoload.php';
use Llack\TossStock\TossStock;
```

### Composer 없이 수동 (PHP 5.6+)

```php
require 'src/TossStock.php';
use Llack\TossStock\TossStock;
```

### 레거시 빌드 (PHP 5.2 ~ 5.5)

네임스페이스 / composer 를 못 쓰는 구형 환경용. 전역 클래스라 `use` 가 필요 없습니다.

```php
require 'legacy/TossStock.php';
$toss = new TossStock('c_xxx', 's_xxx');
```

## 빠른 시작

```php
use Llack\TossStock\TossStock;
use Llack\TossStock\TossStockException;

$toss = new TossStock('c_xxxxxxxx', 's_xxxxxxxx'); // 토큰은 내부에서 자동 관리

try {
    // 시세 (계좌 불필요)
    $prices = $toss->getPrices('005930');            // 삼성전자. 배열도 가능: ['005930','000660']
    echo $prices[0]['lastPrice'], "\n";

    // 계좌 컨텍스트 설정 후 계좌·주문 API 사용
    $accounts = $toss->getAccounts();
    $toss->useAccount($accounts[0]['accountSeq']);

    $bp = $toss->getBuyingPower('KRW');
    $order = $toss->buyLimit('005930', 10, '70000');  // 지정가 매수 10주
    echo $order['orderId'], "\n";
} catch (TossStockException $e) {
    echo $e->getErrorCode();   // 예: insufficient-buying-power
    echo $e->getMessage();
    echo $e->getRequestId();   // CS 문의 시 첨부
    print_r($e->getData());    // 해결 힌트(허용값/호가단위/한도 등)
}
```

## 인증

생성자에 키 두 개만 넘기면 됩니다. 첫 API 호출 시 토큰을 발급하고, 만료가 임박하면 자동으로 재발급합니다.

서버리스 등에서 토큰을 외부 캐시에 보관하려면:

```php
$info = $toss->getTokenInfo();            // ['access_token' => ..., 'expires_at' => ...] 저장
$toss->setAccessToken($info['access_token'], $info['expires_at'] - time()); // 다음 요청에서 재사용
```

## 계좌 컨텍스트

계좌·자산·주문 관련 API 는 `X-Tossinvest-Account` 헤더가 필요합니다.

```php
$toss->useAccount($accountSeq);                 // 기본 계좌 지정
$toss->getHoldings(null, $accountSeq);          // 또는 메서드 인자로 계좌별 호출
```

## 지원 API

| 그룹 | 메서드 |
|------|--------|
| 인증 | `issueToken()` · `getAccessToken()` · `setAccessToken()` · `getTokenInfo()` |
| 계좌 | `getAccounts()` · `useAccount($seq)` |
| 시세 | `getOrderbook($symbol)` · `getPrices($symbols)` · `getTrades($symbol, $count)` · `getPriceLimit($symbol)` · `getCandles($symbol, $interval, $opts)` |
| 종목 | `getStocks($symbols)` · `getStockWarnings($symbol)` |
| 시장 | `getExchangeRate($base, $quote, $dateTime)` · `getKrMarketCalendar($date)` · `getUsMarketCalendar($date)` |
| 자산 | `getHoldings($symbol, $accountSeq)` |
| 주문 | `createOrder($params)` · `modifyOrder($orderId, $params)` · `cancelOrder($orderId)` |
| 주문 헬퍼 | `buyLimit()` · `sellLimit()` · `buyMarket()` · `sellMarket()` · `buyAmount()` |
| 주문내역 | `getOrders($status, $opts)` · `getOrder($orderId)` |
| 거래정보 | `getBuyingPower($currency)` · `getSellableQuantity($symbol)` · `getCommissions()` |
| 메타 | `getLastRateLimit()` · `getLastRequestId()` |

성공 응답은 envelope 의 `result` 페이로드를 그대로 반환하고, 실패는 `TossStockException` 으로 던집니다.

## 주문 ⚠

주문 API 는 **실제로 주문이 체결됩니다.** 충분히 검증한 뒤 사용하세요.

```php
$toss->buyLimit('005930', 10, '70000');       // 지정가 매수
$toss->sellLimit('005930', 10, '72000');      // 지정가 매도
$toss->buyMarket('005930', 10);               // 시장가 매수 (수량)
$toss->buyAmount('AAPL', '100.5');            // 금액 매수 (미국 주식, 정규장)
$toss->modifyOrder($orderId, ['orderType' => 'LIMIT', 'price' => '71000', 'quantity' => '15']);
$toss->cancelOrder($orderId);
```

수량/가격/금액은 정밀도 보존을 위해 **문자열 사용을 권장**합니다(숫자도 허용).

## 에러 처리

모든 실패는 `TossStockException` 입니다.

```php
catch (TossStockException $e) {
    $e->getErrorCode();   // 토스 에러 코드 (또는 OAuth error)
    $e->getMessage();     // 메시지
    $e->getHttpStatus();  // HTTP 상태
    $e->getRequestId();   // 요청 식별자 (CS 문의용)
    $e->getData();        // 해결 힌트 (필드/제약/한도 등), 없으면 null
    $e->getOAuthError();  // 토큰 발급 실패 시 OAuth2 error 코드
}
```

## 옵션

```php
$toss = new TossStock($id, $secret, [
    'timeout'         => 10,        // 요청 타임아웃(초)
    'connect_timeout' => 5,         // 연결 타임아웃(초)
    'verify'          => true,      // TLS 인증서 검증 (끄지 않기를 권장)
    'ca_bundle'       => null,      // CA 번들 경로 (윈도우 SSL 오류 시)
    'base_url'        => null,      // 기본 https://openapi.tossinvest.com
    'account_seq'     => null,      // 기본 계좌 seq
    'user_agent'      => null,      // 사용자 정의 User-Agent
]);
```

## 보안

- TLS 인증서 검증 ON, **TLS 1.2 강제**, **HTTPS 프로토콜 전용**(리다이렉트 차단)
- 비밀키는 폼 바디로만 전송하며 예외·로그에 노출하지 않음
- 심볼 · 주문ID · accountSeq · clientOrderId **입력 검증**, 쿼리 RFC3986 인코딩
- 윈도우 PHP 에서 `cURL error 60 (인증서 검증 실패)` 이 나면 [cacert.pem](https://curl.se/ca/cacert.pem) 을 받아 `ca_bundle` 옵션이나 php.ini `curl.cainfo` 에 지정하세요. (리눅스는 시스템 CA 로 자동 동작)

## 로컬 플레이그라운드

브라우저에서 키를 입력하고 각 API 를 눌러 실제 응답을 확인하는 개발용 도구가 포함되어 있습니다.

```bash
php -S localhost:8000 test/playground.php
# 브라우저로 http://localhost:8000 접속
```

키는 서버 세션에만 보관되며, 주문(실거래)은 별도 확인 절차가 필요합니다.

## 호환성

| 빌드 | PHP | 비고 |
|------|-----|------|
| `src/TossStock.php` | 5.6 ~ 8.x | 네임스페이스 · composer · PSR-4 |
| `legacy/TossStock.php` | 5.2+ | 전역 클래스 · composer 불필요 |

## 면책

본 라이브러리는 비공식 프로젝트이며 토스증권과 무관합니다. 투자 및 매매에는 원금 손실 위험이 따르며, 본 소프트웨어 사용으로 발생하는 어떠한 손실에 대해서도 작성자는 책임지지 않습니다.

## 후원

이 프로젝트가 도움이 되었다면 GitHub Sponsors 로 후원 부탁드립니다. 🙏

## 라이선스

[MIT](LICENSE)
