<?php
/**
 * 토스증권 Open API 로컬 플레이그라운드.
 *
 * 브라우저에서 client_id/client_secret 를 입력하고 각 API 를 눌러 실제 응답을 확인하는 개발용 도구입니다.
 *
 * 실행:
 *   php -S localhost:8000 test/playground.php
 * 그 다음 브라우저로 http://localhost:8000 접속.
 *
 * - 키는 서버 세션에만 보관되며 저장소/디스크 코드에 남지 않습니다 (브라우저엔 세션 쿠키만).
 * - 읽기 API 는 원클릭. 주문(생성/정정/취소)은 실거래이므로 별도 섹션 + 확인 체크가 필요합니다.
 * - 윈도우에서 SSL 오류(cURL 60)가 나면 연결 폼의 "CA 번들 경로"에 cacert.pem 경로를 넣으세요.
 *   (https://curl.se/ca/cacert.pem 다운로드)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../src/TossStock.php';

session_start();

/** 세션 키로 클라이언트 생성 (미연결 시 null). */
function pg_client()
{
    if (empty($_SESSION['cid']) || empty($_SESSION['cs'])) {
        return null;
    }
    $opts = array('timeout' => 15);
    if (!empty($_SESSION['ca']))   { $opts['ca_bundle'] = $_SESSION['ca']; }
    if (!empty($_SESSION['base'])) { $opts['base_url']  = $_SESSION['base']; }
    $t = new \Llack\TossStock\TossStock($_SESSION['cid'], $_SESSION['cs'], $opts);
    if (!empty($_SESSION['seq'])) {
        $t->useAccount($_SESSION['seq']);
    }
    return $t;
}

/** GET/POST 파라미터 헬퍼. */
function pg_param($key, $default = null)
{
    if (isset($_POST[$key]) && $_POST[$key] !== '') { return $_POST[$key]; }
    if (isset($_GET[$key])  && $_GET[$key]  !== '') { return $_GET[$key]; }
    return $default;
}

/** API 호출을 감싸 표준 응답(JSON 직렬화용 배열)으로 변환. */
function pg_run($fn)
{
    $t = pg_client();
    if (!$t) {
        return array('ok' => false, 'error' => array('code' => 'not-connected', 'message' => '먼저 키로 연결하세요.'));
    }
    $t0 = microtime(true);
    try {
        $result = call_user_func($fn, $t);
        return array(
            'ok'        => true,
            'elapsedMs' => round((microtime(true) - $t0) * 1000),
            'requestId' => $t->getLastRequestId(),
            'rateLimit' => $t->getLastRateLimit(),
            'result'    => $result,
        );
    } catch (\Llack\TossStock\TossStockException $e) {
        return array(
            'ok'        => false,
            'elapsedMs' => round((microtime(true) - $t0) * 1000),
            'rateLimit' => $t->getLastRateLimit(),
            'error'     => array(
                'code'       => $e->getErrorCode(),
                'message'    => $e->getMessage(),
                'httpStatus' => $e->getHttpStatus(),
                'requestId'  => $e->getRequestId(),
                'oauth'      => $e->getOAuthError(),
                'data'       => $e->getData(),
            ),
        );
    } catch (\Exception $e) {
        return array('ok' => false, 'error' => array('code' => 'client-error', 'message' => $e->getMessage()));
    }
}

/** 주문(실거래) 액션 가드: confirm=yes 필요. */
function pg_require_confirm()
{
    if (pg_param('confirm') !== 'yes') {
        return array('ok' => false, 'error' => array('code' => 'confirm-required', 'message' => '실거래 주문입니다. 확인 체크가 필요합니다.'));
    }
    return null;
}

$action = pg_param('action');

if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');
    $out = pg_dispatch($action);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function pg_dispatch($action)
{
    switch ($action) {
        // --- 연결 관리 ---
        case 'connect':
            $cid = pg_param('client_id');
            $cs  = pg_param('client_secret');
            if (!$cid || !$cs) {
                return array('ok' => false, 'error' => array('code' => 'invalid', 'message' => 'client_id / client_secret 를 입력하세요.'));
            }
            $_SESSION['cid']  = $cid;
            $_SESSION['cs']   = $cs;
            $_SESSION['ca']   = pg_param('ca_bundle', '');
            $_SESSION['base'] = pg_param('base_url', '');
            $_SESSION['seq']  = '';
            // 실제 검증: 토큰 발급을 시도해 키가 유효한지 확인 (성공해야만 연결로 처리)
            $r = pg_run(function ($t) {
                $t->issueToken();
                $info = $t->getTokenInfo();
                return array('verified' => true, 'tokenExpiresAt' => $info['expires_at']);
            });
            if (empty($r['ok'])) {
                $_SESSION = array();          // 검증 실패 → 키 폐기 (미연결 유지)
                $r['connected'] = false;
                return $r;
            }
            $r['connected'] = true;
            return $r;

        case 'disconnect':
            $_SESSION = array();
            return array('ok' => true, 'connected' => false);

        case 'status':
            return array('ok' => true, 'connected' => !empty($_SESSION['cid']), 'accountSeq' => isset($_SESSION['seq']) ? $_SESSION['seq'] : '');

        case 'setAccount':
            $_SESSION['seq'] = pg_param('accountSeq', '');
            return array('ok' => true, 'accountSeq' => $_SESSION['seq']);

        // --- 시세 (Market Data) ---
        case 'prices':
            return pg_run(function ($t) { return $t->getPrices(pg_param('symbols', '005930')); });
        case 'orderbook':
            return pg_run(function ($t) { return $t->getOrderbook(pg_param('symbol', '005930')); });
        case 'trades':
            return pg_run(function ($t) {
                $c = pg_param('count');
                return $t->getTrades(pg_param('symbol', '005930'), $c !== null ? (int) $c : null);
            });
        case 'priceLimit':
            return pg_run(function ($t) { return $t->getPriceLimit(pg_param('symbol', '005930')); });
        case 'candles':
            return pg_run(function ($t) {
                $opt = array();
                if (pg_param('count') !== null)    { $opt['count'] = (int) pg_param('count'); }
                if (pg_param('before') !== null)   { $opt['before'] = pg_param('before'); }
                if (pg_param('adjusted') !== null) { $opt['adjusted'] = pg_param('adjusted') === 'true'; }
                return $t->getCandles(pg_param('symbol', '005930'), pg_param('interval', '1d'), $opt);
            });

        // --- 종목 정보 (Stock Info) ---
        case 'stocks':
            return pg_run(function ($t) { return $t->getStocks(pg_param('symbols', '005930,AAPL')); });
        case 'warnings':
            return pg_run(function ($t) { return $t->getStockWarnings(pg_param('symbol', '005930')); });

        // --- 시장 정보 (Market Info) ---
        case 'exchangeRate':
            return pg_run(function ($t) {
                return $t->getExchangeRate(pg_param('baseCurrency', 'USD'), pg_param('quoteCurrency', 'KRW'), pg_param('dateTime'));
            });
        case 'krCalendar':
            return pg_run(function ($t) { return $t->getKrMarketCalendar(pg_param('date')); });
        case 'usCalendar':
            return pg_run(function ($t) { return $t->getUsMarketCalendar(pg_param('date')); });

        // --- 계좌·자산 ---
        case 'accounts':
            return pg_run(function ($t) { return $t->getAccounts(); });
        case 'holdings':
            return pg_run(function ($t) { return $t->getHoldings(pg_param('symbol')); });

        // --- 거래 가능 정보 ---
        case 'buyingPower':
            return pg_run(function ($t) { return $t->getBuyingPower(pg_param('currency', 'KRW')); });
        case 'sellable':
            return pg_run(function ($t) { return $t->getSellableQuantity(pg_param('symbol', '005930')); });
        case 'commissions':
            return pg_run(function ($t) { return $t->getCommissions(); });

        // --- 주문 내역 ---
        case 'orders':
            return pg_run(function ($t) {
                $opt = array();
                if (pg_param('symbol') !== null) { $opt['symbol'] = pg_param('symbol'); }
                if (pg_param('from') !== null)   { $opt['from'] = pg_param('from'); }
                if (pg_param('to') !== null)     { $opt['to'] = pg_param('to'); }
                if (pg_param('limit') !== null)  { $opt['limit'] = (int) pg_param('limit'); }
                return $t->getOrders(pg_param('status', 'OPEN'), $opt);
            });
        case 'order':
            return pg_run(function ($t) { return $t->getOrder(pg_param('orderId', '')); });

        // --- 주문 (실거래) ---
        case 'buyLimit':
            $g = pg_require_confirm(); if ($g) { return $g; }
            return pg_run(function ($t) { return $t->buyLimit(pg_param('symbol'), pg_param('quantity'), pg_param('price')); });
        case 'sellLimit':
            $g = pg_require_confirm(); if ($g) { return $g; }
            return pg_run(function ($t) { return $t->sellLimit(pg_param('symbol'), pg_param('quantity'), pg_param('price')); });
        case 'buyMarket':
            $g = pg_require_confirm(); if ($g) { return $g; }
            return pg_run(function ($t) { return $t->buyMarket(pg_param('symbol'), pg_param('quantity')); });
        case 'modify':
            $g = pg_require_confirm(); if ($g) { return $g; }
            return pg_run(function ($t) {
                $p = array('orderType' => pg_param('orderType', 'LIMIT'));
                if (pg_param('price') !== null)    { $p['price'] = pg_param('price'); }
                if (pg_param('quantity') !== null) { $p['quantity'] = pg_param('quantity'); }
                return $t->modifyOrder(pg_param('orderId', ''), $p);
            });
        case 'cancel':
            $g = pg_require_confirm(); if ($g) { return $g; }
            return pg_run(function ($t) { return $t->cancelOrder(pg_param('orderId', '')); });

        default:
            return array('ok' => false, 'error' => array('code' => 'unknown-action', 'message' => '알 수 없는 action: ' . $action));
    }
}

// ----- 여기부터 HTML (GET / ) -----
$connected = !empty($_SESSION['cid']);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>토스증권 Open API 플레이그라운드</title>
<style>
  /* 흰색·회색 미니멀 라이트 테마. 모든 입력/버튼 높이 34px 로 통일, 사각(반경 3px) */
  * { box-sizing:border-box; }
  body { margin:0; background:#fff; color:#1a1a1a; font:14px/1.5 system-ui,"Segoe UI","Malgun Gothic",sans-serif; }
  header { display:flex; align-items:center; gap:10px; padding:13px 18px; border-bottom:1px solid #e5e5e5; flex-wrap:wrap; }
  header h1 { font-size:15px; margin:0; font-weight:600; }
  .pill { font-size:12px; padding:2px 9px; border:1px solid #d4d4d4; color:#666; background:#fafafa; border-radius:3px; }
  .pill.on { color:#0a7d33; border-color:#0a7d33; }
  .pill.off { color:#c2253b; border-color:#c2253b; }
  .wrap { display:grid; grid-template-columns:1fr 1fr; height:calc(100vh - 52px); }
  .left { overflow-y:auto; padding:16px 18px; border-right:1px solid #e5e5e5; }
  .right { overflow-y:auto; padding:16px 18px; background:#fafafa; }
  .card { background:#fff; border:1px solid #e5e5e5; border-radius:3px; padding:14px; margin-bottom:14px; }
  .card h2 { font-size:12px; margin:0 0 10px; color:#888; text-transform:uppercase; letter-spacing:.03em; font-weight:600; }
  .card.danger { border-color:#e3b341; }
  .card.danger h2 { color:#9a6b00; }
  .row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px; }
  label { font-size:12px; color:#666; min-width:92px; }
  input, select, button { height:34px; border-radius:3px; font:13px system-ui,sans-serif; }
  input, select { background:#fff; border:1px solid #d4d4d4; color:#1a1a1a; padding:0 9px; min-width:120px; }
  input:focus, select:focus { outline:none; border-color:#2563eb; }
  input[type=checkbox] { height:auto; min-width:0; width:auto; margin:0; accent-color:#2563eb; }
  .confirm { display:flex; align-items:center; gap:7px; min-width:auto; margin-bottom:10px; color:#9a6b00; font-weight:600; cursor:pointer; }
  button { background:#2563eb; color:#fff; border:1px solid #2563eb; padding:0 14px; font-weight:600; cursor:pointer; }
  button:hover { background:#1d4ed8; }
  button.ghost { background:#fff; color:#1a1a1a; border-color:#d4d4d4; }
  button.ghost:hover { background:#f2f2f2; }
  button.danger { background:#e3b341; border-color:#cc9c1f; color:#3a2c00; }
  button.danger:hover { background:#d9a92f; }
  .ep { border-top:1px solid #f0f0f0; padding:10px 0; }
  .ep:first-of-type { border-top:0; }
  .ep .title { font-weight:600; margin-bottom:6px; font-size:13px; }
  .ep .title small { color:#999; font-weight:400; font-family:ui-monospace,monospace; }
  .fields { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
  .fields .f { display:flex; flex-direction:column; gap:3px; }
  .fields .f span { font-size:10px; color:#999; }
  .fields input { min-width:88px; }
  #result { position:sticky; top:0; }
  .meta { display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:#888; margin-bottom:10px; }
  .meta b { color:#1a1a1a; font-weight:600; }
  .status-ok { color:#0a7d33; } .status-err { color:#c2253b; }
  pre { margin:0; background:#fff; border:1px solid #e5e5e5; border-radius:3px; padding:13px; overflow:auto; font:12.5px/1.5 ui-monospace,Consolas,monospace; white-space:pre-wrap; word-break:break-word; max-height:calc(100vh - 210px); }
  .hint { font-size:12px; color:#999; margin:6px 0 0; }
  a { color:#2563eb; }
  .toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  #banner { padding:9px 12px; border-radius:3px; margin-bottom:10px; font-size:13px; font-weight:600; border:1px solid; display:none; }
  #banner.ok { display:block; background:#eafaf0; border-color:#0a7d33; color:#0a7d33; }
  #banner.err { display:block; background:#fdecee; border-color:#c2253b; color:#c2253b; }
  #toasts { position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:8px; z-index:100; }
  .toast { padding:10px 14px; border-radius:3px; font-size:13px; font-weight:600; background:#fff; border:1px solid; box-shadow:0 4px 14px rgba(0,0,0,.1); }
  .toast.ok { border-color:#0a7d33; color:#0a7d33; }
  .toast.err { border-color:#c2253b; color:#c2253b; }
</style>
</head>
<body>
<header>
  <h1>토스증권 Open API · 플레이그라운드</h1>
  <span id="connPill" class="pill <?php echo $connected ? 'on' : 'off'; ?>"><?php echo $connected ? '연결됨' : '미연결'; ?></span>
  <span id="acctPill" class="pill">계좌: -</span>
  <span style="flex:1"></span>
  <span class="pill">조회는 안전 · 주문은 실거래</span>
</header>

<div class="wrap">
  <div class="left">
    <!-- 연결 -->
    <div class="card">
      <h2>연결 (client_id / client_secret)</h2>
      <div class="row"><label>client_id</label><input id="cid" type="text" style="flex:1"></div>
      <div class="row"><label>client_secret</label><input id="cs" type="password" style="flex:1"></div>
      <div class="row"><label>CA 번들(선택)</label><input id="ca" type="text" placeholder="cacert.pem 경로 (윈도우 SSL 오류 시)" style="flex:1"></div>
      <div class="row"><label>base_url(선택)</label><input id="base" type="text" placeholder="기본값 사용" style="flex:1"></div>
      <div class="row"><button onclick="connect()">연결</button><button class="ghost" onclick="disconnect()">연결 해제(키 삭제)</button></div>
      <p class="hint">키는 서버 세션에만 저장됩니다. 끝나면 "연결 해제"로 삭제하세요.</p>
    </div>

    <!-- 계좌 -->
    <div class="card">
      <h2>계좌 선택 (계좌 스코프 API 용)</h2>
      <div class="row">
        <button class="ghost" onclick="loadAccounts()">계좌 불러오기</button>
        <select id="acctSel" onchange="setAccount()"><option value="">(미선택)</option></select>
      </div>
      <p class="hint">보유주식/매수가능금액/주문 등은 계좌 선택이 필요합니다.</p>
    </div>

    <div id="sections"></div>
  </div>

  <div class="right">
    <div id="result">
      <div id="banner"></div>
      <div class="meta" id="meta"><span>결과가 여기에 표시됩니다.</span></div>
      <pre id="out">// API 버튼을 눌러보세요.</pre>
    </div>
  </div>
</div>

<div id="toasts"></div>

<script>
// 엔드포인트 정의 (label, action, sig=메서드 표기, fields=[{k:키, def:기본값}])
const GROUPS = [
  { title:'시세 (Market Data)', danger:false, items:[
    { label:'현재가', action:'prices', sig:'GET /prices', fields:[{k:'symbols',def:'005930'}] },
    { label:'호가', action:'orderbook', sig:'GET /orderbook', fields:[{k:'symbol',def:'005930'}] },
    { label:'체결', action:'trades', sig:'GET /trades', fields:[{k:'symbol',def:'005930'},{k:'count',def:'10'}] },
    { label:'상/하한가', action:'priceLimit', sig:'GET /price-limits', fields:[{k:'symbol',def:'005930'}] },
    { label:'캔들', action:'candles', sig:'GET /candles', fields:[{k:'symbol',def:'005930'},{k:'interval',def:'1d'},{k:'count',def:'10'},{k:'adjusted',def:'true'}] },
  ]},
  { title:'종목 정보 (Stock Info)', danger:false, items:[
    { label:'종목정보', action:'stocks', sig:'GET /stocks', fields:[{k:'symbols',def:'005930,AAPL'}] },
    { label:'유의사항', action:'warnings', sig:'GET /stocks/{s}/warnings', fields:[{k:'symbol',def:'005930'}] },
  ]},
  { title:'시장 정보 (Market Info)', danger:false, items:[
    { label:'환율', action:'exchangeRate', sig:'GET /exchange-rate', fields:[{k:'baseCurrency',def:'USD'},{k:'quoteCurrency',def:'KRW'}] },
    { label:'국내 장운영', action:'krCalendar', sig:'GET /market-calendar/KR', fields:[{k:'date',def:''}] },
    { label:'미국 장운영', action:'usCalendar', sig:'GET /market-calendar/US', fields:[{k:'date',def:''}] },
  ]},
  { title:'계좌·자산', danger:false, items:[
    { label:'계좌목록', action:'accounts', sig:'GET /accounts', fields:[] },
    { label:'보유주식', action:'holdings', sig:'GET /holdings', fields:[{k:'symbol',def:''}] },
  ]},
  { title:'거래 가능 정보 / 주문 조회', danger:false, items:[
    { label:'매수가능금액', action:'buyingPower', sig:'GET /buying-power', fields:[{k:'currency',def:'KRW'}] },
    { label:'판매가능수량', action:'sellable', sig:'GET /sellable-quantity', fields:[{k:'symbol',def:'005930'}] },
    { label:'수수료', action:'commissions', sig:'GET /commissions', fields:[] },
    { label:'주문목록', action:'orders', sig:'GET /orders', fields:[{k:'status',def:'OPEN'},{k:'symbol',def:''}] },
    { label:'주문상세', action:'order', sig:'GET /orders/{id}', fields:[{k:'orderId',def:''}] },
  ]},
  { title:'주문 ⚠ 실거래 · 실제로 주문이 체결됩니다', danger:true, items:[
    { label:'지정가 매수', action:'buyLimit', sig:'POST /orders', fields:[{k:'symbol',def:'005930'},{k:'quantity',def:'1'},{k:'price',def:''}] },
    { label:'지정가 매도', action:'sellLimit', sig:'POST /orders', fields:[{k:'symbol',def:'005930'},{k:'quantity',def:'1'},{k:'price',def:''}] },
    { label:'시장가 매수', action:'buyMarket', sig:'POST /orders', fields:[{k:'symbol',def:'005930'},{k:'quantity',def:'1'}] },
    { label:'정정', action:'modify', sig:'POST /orders/{id}/modify', fields:[{k:'orderId',def:''},{k:'orderType',def:'LIMIT'},{k:'price',def:''},{k:'quantity',def:''}] },
    { label:'취소', action:'cancel', sig:'POST /orders/{id}/cancel', fields:[{k:'orderId',def:''}] },
  ]},
];

function el(tag, attrs, children){ const e=document.createElement(tag); for(const k in (attrs||{})){ if(k==='class')e.className=attrs[k]; else if(k==='html')e.innerHTML=attrs[k]; else e.setAttribute(k,attrs[k]); } (children||[]).forEach(c=>e.appendChild(c)); return e; }

function toast(kind, msg){
  const box=document.getElementById('toasts');
  const t=el('div',{class:'toast '+kind}); t.textContent=msg; box.appendChild(t);
  setTimeout(function(){ t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(function(){ t.remove(); }, 300); }, 4000);
}

function renderSections(){
  const root = document.getElementById('sections'); root.innerHTML='';
  GROUPS.forEach((g,gi)=>{
    const card = el('div',{class:'card'+(g.danger?' danger':'')});
    card.appendChild(el('h2',{html:g.title}));
    if(g.danger){
      const lbl=el('label',{class:'confirm'});
      lbl.appendChild(el('input',{type:'checkbox',id:'confirmOrders'}));
      lbl.appendChild(el('span',{html:'실제로 주문이 체결됨을 이해했습니다'}));
      card.appendChild(lbl);
    }
    g.items.forEach((it,ii)=>{
      const ep = el('div',{class:'ep'});
      ep.appendChild(el('div',{class:'title',html:it.label+' <small>'+it.sig+'</small>'}));
      const fwrap = el('div',{class:'fields'});
      it.fields.forEach(f=>{
        const box=el('div',{class:'f'});
        box.appendChild(el('span',{html:f.k}));
        const inp=el('input',{type:'text',value:f.def,'data-k':f.k,id:'fld_'+it.action+'_'+f.k});
        box.appendChild(inp); fwrap.appendChild(box);
      });
      const btn=el('button',g.danger?{class:'danger'}:{}); btn.textContent='호출';
      btn.onclick=()=>callEndpoint(it, g.danger);
      const btnBox=el('div',{class:'f'}); btnBox.appendChild(el('span',{html:'&nbsp;'})); btnBox.appendChild(btn);
      fwrap.appendChild(btnBox);
      ep.appendChild(fwrap);
      card.appendChild(ep);
    });
    root.appendChild(card);
  });
}

function gatherParams(it){
  const p={};
  it.fields.forEach(f=>{ const v=document.getElementById('fld_'+it.action+'_'+f.k).value; if(v!=='') p[f.k]=v; });
  return p;
}

async function api(action, params){
  const u = new URLSearchParams(Object.assign({action}, params||{}));
  const t0 = performance.now();
  const res = await fetch('?'+u.toString(), {method:'POST'});
  const txt = await res.text();
  let json; try { json = JSON.parse(txt); } catch(e){ json = {ok:false, error:{code:'parse', message:txt.slice(0,500)}}; }
  json._clientMs = Math.round(performance.now()-t0);
  return json;
}

function show(action, json){
  const meta=document.getElementById('meta'); const out=document.getElementById('out');
  const ok = json.ok;
  const bits=[];
  bits.push('<span class="'+(ok?'status-ok':'status-err')+'"><b>'+(ok?'OK':'FAIL')+'</b></span>');
  bits.push('action=<b>'+action+'</b>');
  if(json.error&&json.error.httpStatus) bits.push('HTTP <b>'+json.error.httpStatus+'</b>');
  if(json.error&&json.error.code) bits.push('code=<b>'+json.error.code+'</b>');
  if(typeof json.elapsedMs!=='undefined') bits.push('server <b>'+json.elapsedMs+'ms</b>');
  bits.push('rtt <b>'+json._clientMs+'ms</b>');
  if(json.requestId) bits.push('reqId=<b>'+json.requestId+'</b>');
  if(json.rateLimit) bits.push('rate=<b>'+JSON.stringify(json.rateLimit)+'</b>');
  meta.innerHTML = bits.join(' &nbsp;·&nbsp; ');
  const body = ok ? (typeof json.result!=='undefined'?json.result:json) : (json.error||json);
  out.textContent = JSON.stringify(body, null, 2);

  // 배너 + 토스트 알림
  const banner=document.getElementById('banner');
  if(ok){
    banner.className='ok'; banner.textContent='✓ '+action+' 성공'+(typeof json.elapsedMs!=='undefined'?' · '+json.elapsedMs+'ms':'');
    toast('ok','✓ '+action+' 성공');
  } else {
    const em=(json.error&&(json.error.message||json.error.code))||'요청 실패';
    const code=(json.error&&json.error.code)?(' ['+json.error.code+']'):'';
    banner.className='err'; banner.textContent='✗ '+action+' 실패'+code+' — '+em;
    toast('err','✗ '+action+' 실패 — '+em);
  }
}

async function callEndpoint(it, danger){
  const params = gatherParams(it);
  if(danger){
    if(!document.getElementById('confirmOrders').checked){ alert('주문 섹션 상단의 확인 체크를 먼저 켜세요.'); return; }
    if(!confirm('실제로 주문이 체결됩니다.\n'+it.label+' '+JSON.stringify(params)+'\n계속할까요?')) return;
    params.confirm='yes';
  }
  document.getElementById('meta').innerHTML='<span>호출 중… '+it.action+'</span>';
  const json = await api(it.action, params);
  show(it.action, json);
}

async function connect(){
  const json = await api('connect', {
    client_id: document.getElementById('cid').value.trim(),
    client_secret: document.getElementById('cs').value.trim(),
    ca_bundle: document.getElementById('ca').value.trim(),
    base_url: document.getElementById('base').value.trim(),
  });
  refreshStatus();
  document.getElementById('cs').value='';
  show('연결', json);
}
async function disconnect(){ const j=await api('disconnect'); document.getElementById('acctSel').innerHTML='<option value="">(미선택)</option>'; refreshStatus(); show('연결 해제', j); }

async function loadAccounts(){
  const json = await api('accounts');
  show('accounts', json);
  const sel=document.getElementById('acctSel'); sel.innerHTML='<option value="">(미선택)</option>';
  if(json.ok && Array.isArray(json.result)){
    json.result.forEach(a=>{ const o=el('option',{value:a.accountSeq}); o.textContent='seq '+a.accountSeq+' · '+a.accountType+' · ****'+String(a.accountNo).slice(-4); sel.appendChild(o); });
    if(json.result.length){ sel.value=json.result[0].accountSeq; setAccount(); }
  }
}
async function setAccount(){ const seq=document.getElementById('acctSel').value; const j=await api('setAccount',{accountSeq:seq}); refreshStatus(); }

async function refreshStatus(){
  const j = await api('status');
  const cp=document.getElementById('connPill'); const ap=document.getElementById('acctPill');
  if(j.connected){ cp.textContent='연결됨'; cp.className='pill on'; } else { cp.textContent='미연결'; cp.className='pill off'; }
  ap.textContent='계좌: '+(j.accountSeq? j.accountSeq : '-');
}

renderSections();
refreshStatus();
</script>
</body>
</html>
