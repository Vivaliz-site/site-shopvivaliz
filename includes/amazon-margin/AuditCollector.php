<?php
declare(strict_types=1);

require_once __DIR__ . '/MarginMath.php';
require_once __DIR__ . '/FinancialStatus.php';
require_once __DIR__ . '/CurrentPriceMargin.php';
require_once __DIR__ . '/CurrentFeeEstimates.php';
require_once __DIR__ . '/HistoricalFulfillment.php';
require_once __DIR__ . '/DbaOrderGroup.php';
require_once __DIR__ . '/KitCost.php';
require_once __DIR__ . '/ProductMatch.php';
require_once __DIR__ . '/RateLimitPolicy.php';
require_once __DIR__ . '/OlistQuotaPolicy.php';
require_once __DIR__ . '/ReadErrorPolicy.php';
require_once __DIR__ . '/OlistAmazonOrderLink.php';
require_once __DIR__ . '/OlistAdsIndex.php';
require_once __DIR__ . '/OlistUiCapture.php';
require_once __DIR__ . '/OlistProductTabCapture.php';

/**
 * Read-only Amazon SP-API + Olist/Tiny v3 collection.
 * Never creates or updates products, listings or prices.
 * Store only SKU-level financial summaries, not customer/order PII.
 */
final class SvAmazonMarginCollector
{
    /**
     * BUYABLE belongs to the Amazon offer, not to the linked Olist product.
     * Olist's product detail also has an `active` boolean. Never allow its
     * value to overwrite the Amazon offer's availability in persisted rows.
     */
    public static function offerIsBuyable(mixed $status): bool
    {
        return is_array($status) && in_array('BUYABLE', $status, true);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $product */
    public static function mergeProductForOffer(array $row, array $product): array
    {
        $buyable = self::offerIsBuyable($row['amazon_status'] ?? null);
        $merged = array_merge($row, $product);
        $merged['erp_product_active'] = ($product['active'] ?? false) === true;
        $merged['active'] = $buyable;
        return $merged;
    }

    private array $productsByEan = [];
    private array $productsBySku = [];
    private array $productDetails = [];
    private array $eanByAsin = [];
    private array $packageByAsin = [];
    private array $programByOrder = [];
    private array $warnings = [];
    private $onProgress = null;
    private string $marketplace;

    // Numeric-only Seller SKUs become integer array keys in PHP.
    public static function skuExcerpt(string|int $sku): string
    {
        return substr((string)$sku,0,80);
    }

    private function note(string $stage,int $done=0,?int $total=null): void
    {
        if ($this->onProgress !== null) ($this->onProgress)($stage,$done,$total);
    }
    private string $sellerId;

    public function __construct(private SvAmazonClient $amazon, private string $tinyToken)
    {
        if ($tinyToken === '') throw new RuntimeException('tiny_token_missing');
        $this->marketplace = $amazon->marketplaceId();
        $this->sellerId = trim((string)(getenv('AMAZON_SELLER_ID') ?: getenv('AMAZON_MERCHANT_ID') ?: ''));
        if ($this->sellerId === '') {
            $ops = (string)(getenv('SHOPVIVALIZ_SHARED_DIR') ?: '/home/ubuntu/shopvivaliz-deploy/shared');
            $path = rtrim($ops, '/') . '/ops/amazon-seller-listings-full.json';
            $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
            $this->sellerId = is_array($data) ? trim((string)($data['sellerId'] ?? '')) : '';
        }
        if ($this->sellerId === '') throw new RuntimeException('amazon_seller_id_missing');
    }

    private function a(string $method, string $path, array $query = []): array
    {
        // Exponential, bounded backoff for the SP-API per-operation token bucket.
        // A few fast retries are not sufficient when a shared seller/application
        // exhausted an operation quota during a complete catalog scan.
        $delays = SvAmazonMarginRateLimitPolicy::delays();
        foreach ($delays as $attempt => $seconds) {
            try {
                $r = $this->amazon->request($method, $path, $query);
                if ($r['status'] !== 200) throw new RuntimeException('amazon_http_unexpected');
                return $r['data'];
            } catch (SvMarketplaceException $e) {
                if (!SvAmazonMarginRateLimitPolicy::retryable($e->httpStatus)) throw $e;
                sleep($seconds);
            }
        }
        // The final request is still protected by the persistent queue
        // cooldown; do not loop forever when Amazon denies capacity.
        $r = $this->amazon->request($method, $path, $query);
        if ($r['status'] !== 200) throw new RuntimeException('amazon_http_unexpected');
        return $r['data'];
    }

    private function tiny(string $path): array
    {
        // Rate limit is PER OLIST ACCOUNT, shared with other integrations.
        // Headers indicate the remaining budget and seconds to reset; preserve
        // capacity for the ERP's regular workflows instead of burst-reading it.
        for ($attempt=0;$attempt<6;$attempt++) {
            $headers=[];
            $ch=curl_init('https://api.tiny.com.br/public-api/v3'.$path);
            if ($ch===false) throw new RuntimeException('tiny_transport_init_failed');
            curl_setopt_array($ch,[
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->tinyToken,'Accept: application/json'],
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>35,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int{
                    SvAmazonMarginOlistQuotaPolicy::header($line,$headers);
                    return strlen($line);
                },
            ]);
            $body=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status===200 && is_string($body)) {
                $decoded=json_decode($body,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($decoded))throw new RuntimeException('tiny_invalid_response');
                $cooloff=SvAmazonMarginOlistQuotaPolicy::cooloffAfterSuccess($headers);
                if ($cooloff>0) sleep($cooloff);
                return $decoded;
            }
            if (!SvAmazonMarginOlistQuotaPolicy::retryable($status) || $attempt===5) {
                // HTTP 401/403 are authorization errors, never bypassed by retry.
                throw new RuntimeException('tiny_http_'.$status);
            }
            sleep(SvAmazonMarginOlistQuotaPolicy::retryDelay($status,$headers,$attempt));
        }
        throw new RuntimeException('tiny_max_retries');
    }

    private function listings(array $selectedSkus): array
    {
        $seen = [];
        $next = null;
        $pages = 0;
        do {
            $q = ['marketplaceIds'=>$this->marketplace, 'includedData'=>'summaries,offers', 'pageSize'=>20];
            if ($next !== null) $q['pageToken'] = $next;
            $data = $this->a('GET', '/listings/2021-08-01/items/' . rawurlencode($this->sellerId), $q);
            foreach (($data['items'] ?? []) as $item) {
                $sku = trim((string)($item['sku'] ?? ''));
                if ($sku === '' || isset($seen[$sku])) continue;
                if ($selectedSkus && !in_array($sku, $selectedSkus, true)) continue;
                $summary = $item['summaries'][0] ?? [];
                $seen[$sku] = [
                    'seller_sku'=>$sku,
                    'asin'=>trim((string)($summary['asin'] ?? '')),
                    'title'=>(string)($summary['itemName'] ?? ''),
                    'amazon_status'=>$summary['status'] ?? [],
                    'product_type'=>(string)($summary['productType'] ?? ''),
                    'listed_price'=>self::validPrice($item['offers'][0]['price']['amount'] ?? null),
                ];
            }
            $next = $data['pagination']['nextToken'] ?? null;
            $pages++;
            $this->note('loading_listings',count($seen),null);
            if ($pages >= 250 && $next !== null) throw new RuntimeException('listing_pagination_incomplete');
            usleep(260000);
        } while (is_string($next) && $next !== '');
        foreach ($selectedSkus as $sku) {
            if (!isset($seen[$sku])) $this->warnings[] = 'not_found_listing:' . self::skuExcerpt($sku);
        }
        return $seen;
    }

    /**
     * Classify the Amazon Pricing item WITHOUT promoting HTTP 200 / Success to
     * a verified price when Product.Offers is empty. This is a read-only
     * contract: registered Listings prices are never substituted as sale prices.
     *
     * @return array{price: ?float, price_source: string, price_state: string, fulfillment_channel: string}
     */
    public static function effectivePriceState(array $record): array
    {
        $empty = [
            'price' => null,
            'price_source' => 'UNVERIFIED',
            'price_state' => 'PRICING_OFFERS_EMPTY',
            'fulfillment_channel' => 'UNKNOWN',
        ];
        if (strtoupper(trim((string)($record['status'] ?? 'Success'))) !== 'SUCCESS') {
            $empty['price_state'] = 'PROVIDER_ITEM_ERROR';
            return $empty;
        }

        $offers = $record['Product']['Offers'] ?? [];
        if (!is_array($offers) || $offers === []) {
            return $empty;
        }

        $offer = $offers[0] ?? null;
        $channel = is_array($offer) ? trim((string)($offer['FulfillmentChannel'] ?? '')) : '';
        $price = self::validPrice(is_array($offer)
            ? ($offer['BuyingPrice']['ListingPrice']['Amount'] ?? null)
            : null);

        return [
            'price' => $price,
            'price_source' => $price !== null ? 'AMAZON_SPAPI_PRICING' : 'UNVERIFIED',
            'price_state' => $price !== null ? 'EFFECTIVE_OFFER' : 'INVALID_OFFER_PRICE',
            'fulfillment_channel' => $channel !== '' ? $channel : 'UNKNOWN',
        ];
    }

    private function pricing(array &$listings): void
    {
        $chunks=array_chunk(array_keys($listings),20);
        foreach ($chunks as $batch=>$chunk) {
            $res = $this->a('GET','/products/pricing/v0/price',[
                'MarketplaceId'=>$this->marketplace,'ItemType'=>'Sku','Skus'=>implode(',',$chunk),
            ]);
            $seen = [];
            foreach ($res['payload'] ?? [] as $r) {
                $sku = (string)($r['SellerSKU'] ?? '');
                if (!array_key_exists($sku, $listings)) continue;
                $priceState = self::effectivePriceState($r);
                foreach ($priceState as $key => $value) {
                    $listings[$sku][$key] = $value;
                }
                $live = $priceState['price'];
                if ($live !== null && $listings[$sku]['listed_price'] !== null
                    && abs($live - $listings[$sku]['listed_price']) >= 0.01) {
                    $listings[$sku]['price_divergence'] = true;
                }
                if ($priceState['price_state'] === 'PRICING_OFFERS_EMPTY'
                    && in_array('BUYABLE', $listings[$sku]['amazon_status'] ?? [], true)) {
                    $this->warnings[] = 'buyable_pricing_offers_empty:' . self::skuExcerpt($sku);
                }
                if ($priceState['price_state'] === 'PROVIDER_ITEM_ERROR') {
                    $this->warnings[] = 'pricing_provider_item_error:' . self::skuExcerpt($sku);
                }
                $seen[$sku] = true;
            }
            foreach ($chunk as $sku) {
                if (!isset($seen[$sku])) {
                    $listings[$sku]['price'] = null;
                    $listings[$sku]['price_source'] = 'UNVERIFIED';
                    $listings[$sku]['price_state'] = 'PRICING_RESPONSE_MISSING';
                    $listings[$sku]['fulfillment_channel'] = 'UNKNOWN';
                }
            }
            $this->note('loading_prices',min(($batch+1)*20,count($listings)),count($listings));
            // Pricing calls are much more restricted than Listings search.
            usleep(2100000);
        }
    }

    private static function validPrice(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $price = (float)$value;
        return is_finite($price) && $price > 0 ? round($price,2) : null;
    }

    private function tinyIndex(): void
    {
        $unique = [];
        for ($page = 0; $page < 500; $page++) {
            $res = $this->tiny('/produtos?limit=100&offset=' . ($page * 100));
            if (!is_array($res['itens'] ?? null)) throw new RuntimeException('tiny_products_schema_changed');
            $new = 0;
            foreach ($res['itens'] as $row) {
                $id = (int)($row['id'] ?? 0);
                if (!$id || isset($unique[$id])) continue;
                $unique[$id] = true;
                $new++;
                if ((string)($row['situacao'] ?? '') !== 'A') continue;
                $ean = trim((string)($row['gtin'] ?? ''));
                $sku = trim((string)($row['sku'] ?? ''));
                $candidate = [
                    'id'=>$id, 'sku'=>$sku, 'gtin'=>$ean,
                    'name'=>(string)($row['descricao'] ?? ''),
                ];
                if ($ean !== '') $this->productsByEan[$ean][] = $candidate;
                if ($sku !== '') $this->productsBySku[strtolower($sku)][] = $candidate;
            }
            $this->note('mapping_erp_products',count($unique),
                (int)($res['paginacao']['total'] ?? 0) ?: null);
            if (!$new || ($page + 1) * 100 >= (int)($res['paginacao']['total'] ?? 0)) break;
            if ($page === 499) throw new RuntimeException('tiny_pagination_incomplete');
            usleep(240000);
        }
    }

    private function ean(string $asin): ?string
    {
        if ($asin === '') return null;
        if (array_key_exists($asin,$this->eanByAsin)) return $this->eanByAsin[$asin];
        $res = $this->a('GET','/catalog/2022-04-01/items/'.rawurlencode($asin),[
            'marketplaceIds'=>$this->marketplace,'includedData'=>'identifiers,attributes',
        ]);
        $this->packageByAsin[$asin]=SvAmazonCurrentPriceMargin::amazonPackage(
            is_array($res['attributes']??null)?$res['attributes']:[]);
        $codes=[];
        foreach (($res['identifiers'] ?? []) as $group) {
            foreach (($group['identifiers'] ?? []) as $v) {
                if (in_array(strtoupper((string)($v['identifierType'] ?? '')),['EAN','GTIN'],true)) {
                    $codes[] = (string)($v['identifier'] ?? '');
                }
            }
        }
        $codes = array_values(array_unique(array_filter($codes)));
        $this->eanByAsin[$asin] = count($codes)===1 ? $codes[0] : null;
        usleep(240000);
        return $this->eanByAsin[$asin];
    }

    private function product(int $id): array
    {
        if (isset($this->productDetails[$id])) return $this->productDetails[$id];
        $details = $this->tiny('/produtos/' . $id);
        $p = $details['produto'] ?? $details;
        $history = $this->tiny('/produtos/'.$id.'/custos?limit=100&offset=0');
        $recent = array_values(array_filter($history['itens'] ?? [], static function($row): bool {
            return is_array($row) && self::validPrice($row['precoCusto'] ?? null)!==null
                && preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($row['data'] ?? ''))===1;
        }));
        usort($recent, static fn($a,$b): int => strcmp((string)$b['data'],(string)$a['data']));
        $cost = $recent[0] ?? null;
        return $this->productDetails[$id] = [
            'erp_id'=>$id, 'erp_sku'=>(string)($p['sku'] ?? ''),
            'erp_gtin'=>(string)($p['gtin'] ?? ''), 'active'=>(string)($p['situacao'] ?? '')==='A',
            'last_cost'=>isset($cost['precoCusto']) ? (float)$cost['precoCusto'] : null,
            'last_cost_date'=>$cost['data'] ?? null,
            'last_cost_source'=>'OLIST_TINY_V3_PRODUCT_COST_HISTORY',
            'erp_product_type'=>(string)($p['tipo']??''),
            'erp_description'=>(string)($p['descricao']??''),
            'erp_package'=>SvAmazonCurrentPriceMargin::erpPackage(
                is_array($p['dimensoes']??null)?$p['dimensoes']:[]),
            'erp_kit_component_count'=>is_array($p['kit']??null) ? count($p['kit']) : 0,
            'erp_kit_components'=>is_array($p['kit']??null)
                ? array_map(static fn($c):array=>[
                    'id'=>$c['produto']['id']??null,
                    'quantity'=>$c['quantidade']??null,
                ],$p['kit']) : [],
        ];
    }

    /** @return array{shipments:array,fees:array,order_skus:array} */
    private function finances(string $postedAfter, ?string $feesAfter = null): array
    {
        // Finance history may go back 90 days to establish ERP identity, but
        // DBA freight is attributable only within the report's sale window.
        $feesAfter ??= $postedAfter;
        $result = ['shipments'=>[], 'fees'=>[], 'order_skus'=>[]];
        $next = null;
        for ($page=0;$page<150;$page++) {
            $query = [
                'postedAfter'=>$postedAfter,'marketplaceId'=>$this->marketplace,'pageSize'=>100,
            ];
            if ($next !== null) $query['nextToken'] = $next;
            $res = $this->a('GET','/finances/2024-06-19/transactions',$query);
            foreach (($res['payload']['transactions'] ?? []) as $t) {
                $type = $t['transactionType'] ?? '';
                $ids = [];
                foreach (($t['relatedIdentifiers'] ?? []) as $identifier) {
                    $key = (string)($identifier['relatedIdentifierName'] ?? '');
                    $ids[$key] = (string)($identifier['relatedIdentifierValue'] ?? '');
                }
                $order = $ids['ORDER_ID'] ?? '';
                if ($order !== ''
                    && SvAmazonMarginOlistOrderLink::postageInFinancialWindow($t,$feesAfter)) {
                    $result['fees'][$order][] = (float)($t['totalAmount']['currencyAmount'] ?? 0);
                }
                if ($type !== 'Shipment') continue;
                foreach (($t['items'] ?? []) as $item) {
                    $context = [];
                    foreach (($item['contexts'] ?? []) as $c) {
                        if (!empty($c['sku'])) {$context=$c;break;}
                    }
                    $sku = (string)($context['sku'] ?? '');
                    if ($sku === '') continue;
                    $qty = (int)($context['quantityShipped'] ?? 0);
                    if ($qty < 1) continue;
                    if ($order !== '') $result['order_skus'][$order][$sku] = true;
                    $result['shipments'][$sku][] = [
                        'order'=>$order, 'quantity'=>$qty,
                        'posted_at'=>(string)($t['postedDate'] ?? ''),
                        'net_amount'=>(float)($item['totalAmount']['currencyAmount'] ?? 0),
                        // Authoritative sale gross, including buyer-paid shipping
                        // net of shipping/promo rebates, not today's listing price.
                        'sale_gross_cents'=>SvAmazonMarginMath::transactionGrossCents($item),
                    ];
                }
            }
            $next = $res['payload']['nextToken'] ?? null;
            $this->note('reading_transactions',$page+1,null);
            if (!is_string($next) || $next === '') return $result;
            usleep(520000);
        }
        throw new RuntimeException('finance_pagination_incomplete');
    }

    /** Read only official Amazon Orders v2026 fulfillment; cache per order. */
    private function orderEvidence(string $order): ?array
    {
        if ($order === '') return null;
        if (array_key_exists($order,$this->programByOrder)) {
            return $this->programByOrder[$order];
        }
        $data=$this->a('GET','/orders/2026-01-01/orders/'.rawurlencode($order),
            ['includedData'=>'FULFILLMENT']);
        $evidence=SvAmazonMarginHistoricalFulfillment::fromOrder($data);
        $this->programByOrder[$order]=$evidence;
        usleep(280000);
        return $evidence;
    }

    private function orderIsDba(string $order): ?bool
    {
        $evidence=$this->orderEvidence($order);
        return $evidence===null ? null : $evidence['is_dba'];
    }

    /**
     * Do not substitute the last observed offer channel when Pricing becomes
     * empty: only independently verified in-period Orders may classify history.
     */
    private function historicalChannel(array $shipments): ?string
    {
        $orders=[];
        $seen=[];
        foreach ($shipments as $shipment) {
            $id=(string)($shipment['order']??'');
            if ($id==='' || !empty($seen[$id]) && $seen[$id] === 'missing') return null;
            if (isset($seen[$id])) continue;
            $evidence=$this->orderEvidence($id);
            if ($evidence===null) return null;
            $seen[$id]='seen';
            $orders[]=$evidence;
        }
        return SvAmazonMarginHistoricalFulfillment::resolve($orders);
    }

    /** @param list<string> $selectedSkus */
    public function collect(array $selectedSkus = [], ?string $postedAfter = null, ?callable $onProgress=null): array
    {
        $postedAfter ??= gmdate('Y-m-d\T00:00:00\Z', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00Z$/', $postedAfter)) {
            throw new InvalidArgumentException('posted_after_invalid');
        }
        $this->onProgress=$onProgress;
        $selectedSkus = array_values(array_unique($selectedSkus));
        $this->note('loading_listings');
        $listings = $this->listings($selectedSkus);
        $this->note('loading_prices',0,count($listings));
        $this->pricing($listings);
        $this->note('mapping_erp_products');
        $this->tinyIndex();
        // An Olist Anuncios export is trusted only if recent and maps an exact
        // Seller SKU to a unique ACTIVE product in this live Olist API index.
        // Old XLS references are informational; never certify a historical link.
        try {
            $adsIndex=SvAmazonMarginOlistAdsIndex::load();
        } catch (Throwable $e) {
            $adsIndex=[];
            $this->warnings[]='olist_ads_index_unreadable:'.get_class($e);
        }
        // This source is independently labelled AUTHENTICATED UI, never XLS.
        // A present but corrupt capture is a hard read failure: publishing
        // new certified costs without its conflict evidence is unsafe.
        $uiCapture=SvAmazonMarginOlistUiCapture::load();
        // Different evidence source: Olist product-edit -> Anuncios exact links.
        // A present, incomplete or corrupt active-product census must abort
        // instead of silently certifying older contradictory costs.
        $productTabCapture=SvAmazonMarginOlistProductTabCapture::load();
        $this->note('reading_transactions');
        // Order-to-product identity may predate the 30-day margin window.
        // Fetch 90 days once, but calculate money ONLY for postedAfter sales.
        $mappingAfter = gmdate('Y-m-d\\T00:00:00\\Z',strtotime('-90 days'));
        $financeFrom = strcmp($postedAfter,$mappingAfter)<0 ? $postedAfter : $mappingAfter;
        $fin = $this->finances($financeFrom,$postedAfter);
        $this->note('calculating_margins',0,count($listings));
        $rows = [];
        $taxBps = (int)(getenv('SV_AMAZON_MARGIN_TAX_BPS') ?: 1400);
        $processed=0;
        foreach ($listings as $sku=>$listing) {
            $processed++;
            if ($processed===1 || $processed%10===0) {
                $this->note('calculating_margins',$processed,count($listings));
            }
            $row = $listing;
            $row['active'] = self::offerIsBuyable($row['amazon_status'] ?? null);
            $row += ['ean'=>null, 'erp_id'=>null, 'erp_sku'=>null,'last_cost'=>null,
                'last_cost_date'=>null,'margin_min_bps'=>null,'margin_max_bps'=>null,
                'margin_weighted_bps'=>null,'confirmed_sales'=>0,'pending_sales'=>0,
                'confirmed_units'=>0,'profit_cents'=>0,
                'realized_sale_gross_cents'=>null,'realized_unit_price'=>null,
                'status'=>'unverified'];
            // Historical financial reconciliation must survive a Seller Flex
            // removal from BUYABLE: the same period's shipments still exist.
            $recentShipments=array_values(array_filter(
                $fin['shipments'][$sku]??[],
                static fn(array $s):bool => SvAmazonMarginOlistOrderLink::inFinancialWindow(
                    (string)($s['posted_at']??''),$postedAfter)
            ));
            $asin = $row['asin'];
            try {
                $ean = $this->ean($asin);
                $row['ean'] = $ean;
                $row['amazon_package']=$this->packageByAsin[$asin]??null;
                // The catalog GTIN alone does not identify the *ERP-linked*
                // product of an Amazon offer. A user may publish Amazon with
                // another GTIN and link it to a valid SKU in one of the three
                // ERP Amazon integrations. First use strict GTIN/SKU identity;
                // only if missing/contradictory join actual Amazon ORDER_ID
                // to the imported Olist order and its product ID.
                $link = SvAmazonMarginProductMatch::resolve($sku,$ean,
                    $this->productsByEan,$this->productsBySku);
                $prod = null;
                if ($link['status']==='mapped') {
                    $prod = $this->product((int)$link['id']);
                    if (!$prod['active'] || ($ean!==null&&$ean!==''&&$prod['erp_gtin']!==$ean)
                        || ($link['method']==='SELLER_SKU_EXACT'
                            && strcasecmp($prod['erp_sku'],(string)$sku)!==0)) {
                        $prod=null;
                        $link=['status'=>'erp_identity_mismatch','id'=>null,'method'=>null,
                            'reason'=>'product_detail_identity_mismatch'];
                    }
                }
                if ($link['status']!=='mapped'
                    && (in_array('BUYABLE',$row['amazon_status']??[],true)
                        || $recentShipments!==[])
                    && !empty($fin['shipments'][$sku])) {
                    $orderLink = SvAmazonMarginOlistOrderLink::resolve(
                        (string)$sku,$fin['shipments'][$sku],$fin['order_skus'],
                        fn(string $path):array => $this->tiny($path));
                    if ($orderLink['status']==='mapped') {
                        $candidate=$this->product((int)$orderLink['id']);
                        if ($candidate['active']) {
                            $prod=$candidate;
                            $link=$orderLink;
                            $row['erp_integration']=$orderLink['integration'];
                            $row['erp_order_evidence_count']=$orderLink['evidence_count'];
                            $row['erp_gtin_differs_from_amazon']=($ean!==null&&$ean!==''
                                &&(string)$candidate['erp_gtin']!==$ean);
                        } else {
                            // An order history can reference a product that has
                            // since been archived. Do not treat it as mapped now.
                            $link=['status'=>'erp_identity_mismatch','id'=>null,
                                'method'=>null,'reason'=>'olist_order_product_inactive'];
                        }
                    } elseif ($orderLink['status']==='ambiguous_erp') {
                        $link=$orderLink; // Do not use a disputed cost.
                    }
                }
                // Recent Olist Anuncios exports are the independent direct
                // Seller-SKU -> ERP SKU link. Revalidate the referenced SKU
                // against the CURRENT API index and product detail.
                // Never override conflicting verified Amazon ORDER evidence.
                $adLink=SvAmazonMarginOlistAdsIndex::lookup(
                    $adsIndex,(string)$sku,$this->productsBySku);
                if ($adLink['status']==='historical') {
                    $row['erp_historical_candidate']=[
                        'erp_sku'=>$adLink['candidate_erp_sku'],
                        'integration'=>$adLink['integration'],
                        'observed_at'=>$adLink['observed_at'],
                        'source'=>'OLIST_ERP_ANUNCIOS_EXPORT',
                    ];
                }
                if ($link['status']!=='mapped' && $prod===null
                    && $adLink['status']==='mapped'
                    && ($link['reason']??'')!=='historical_amazon_orders_disagree_on_product') {
                    $candidate=$this->product((int)$adLink['id']);
                    if ($candidate['active']
                        && (string)$candidate['erp_sku']
                            ===(string)($adsIndex['entries'][(string)$sku]['erp_sku']??'')) {
                        $prod=$candidate;
                        $link=$adLink;
                        $row['erp_integration']=$adLink['integration'];
                        $row['erp_export_observed_at']=$adLink['observed_at'];
                        $row['erp_gtin_differs_from_amazon']=($ean!==null&&$ean!==''
                            && (string)$candidate['erp_gtin']!==$ean);
                    }
                }
                // A browser capture of Olist's actual Anuncios -> Produto (SKU)
                // is corroborating current identity evidence, NOT an XLS export.
                // The capture never supplies a cost or price. Even when the
                // existing EAN or historical order matched an active product,
                // contradicting current UI data invalidates certification.
                $uiLink=SvAmazonMarginOlistUiCapture::lookup(
                    $uiCapture,(string)$sku,$this->productsBySku);
                $decision=SvAmazonMarginOlistUiCapture::decision($link,$prod,$uiLink);
                if ($decision==='hold') {
                    $row['erp_ui_conflict']='current_or_historical_ui_disagrees_with_'
                        .(string)($link['method']??$link['status']??'unknown');
                    $row['erp_ui_observed_at']=$uiLink['observed_at']
                        ??($uiCapture['observed_at']??null);
                    $row['erp_ui_candidate_sku']=$uiLink['erp_sku']
                        ??$uiLink['candidate_erp_sku']??null;
                    $link=['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                        'reason'=>'olist_ui_product_identity_conflict'];
                    $prod=null;
                } elseif ($decision==='use_ui') {
                    $candidate=$this->product((int)$uiLink['id']);
                    if (!$candidate['active']
                        || (string)$candidate['erp_sku']!==(string)$uiLink['erp_sku']) {
                        $link=['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                            'reason'=>'olist_ui_product_inactive_or_changed'];
                        $prod=null;
                    } else {
                        $prod=$candidate;
                        $link=$uiLink;
                        $row['erp_integration']=$uiLink['integration'];
                        $row['erp_ui_observed_at']=$uiLink['observed_at'];
                        $row['erp_gtin_differs_from_amazon']=($ean!==null&&$ean!==''
                            &&(string)$candidate['erp_gtin']!==$ean);
                    }
                } elseif ($decision==='verified_same') {
                    $row['erp_ui_observed_at']=$uiLink['observed_at'];
                    $row['erp_ui_verified_same_product']=true;
                } elseif ($decision==='reference_only') {
                    $row['erp_historical_ui_candidate']=[
                        'erp_sku'=>$uiLink['candidate_erp_sku']??null,
                        'observed_at'=>$uiLink['observed_at']??null,
                        'source'=>'OLIST_ERP_AUTHENTICATED_UI_CAPTURE'];
                }
                // The product edit's authenticated Anuncios tab is separate
                // from Olist's /anuncios listing and XLS exports. Accept only
                // complete, screenshot-verified, 48h-fresh associations whose
                // ERP SKU and ID still match the LIVE official V3 index/detail.
                // In an existing Olist UI conflict, this is corroboration
                // only: a second UI cannot silently clear a disputed link.
                $productTabLink=SvAmazonMarginOlistProductTabCapture::lookup(
                    $productTabCapture,(string)$sku,$this->productsBySku);
                if (!empty($row['erp_ui_conflict'])) {
                    if (($productTabLink['status']??'absent')!=='absent')
                        $row['erp_product_tab_crosscheck_pending']=true;
                } else {
                    $productTabDecision=SvAmazonMarginOlistUiCapture::decision(
                        $link,$prod,$productTabLink);
                    if ($productTabDecision==='hold') {
                        $row['erp_ui_conflict']='olist_product_tab_identity_conflict';
                        $row['erp_ui_candidate_sku']=$productTabLink['erp_sku']
                            ??$productTabLink['candidate_erp_sku']??null;
                        $row['erp_product_tab_observed_at']=$productTabLink['observed_at']
                            ??($productTabCapture['observed_at']??null);
                        $link=['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                            'reason'=>'olist_product_tab_conflicts_with_other_identity_evidence'];
                        $prod=null;
                    } elseif ($productTabDecision==='use_ui') {
                        $candidate=$this->product((int)$productTabLink['id']);
                        if (!$candidate['active']
                            || (string)$candidate['erp_sku']!==(string)$productTabLink['erp_sku']) {
                            $link=['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                                'reason'=>'olist_product_tab_sku_or_activation_changed'];
                            $prod=null;
                        } else {
                            $prod=$candidate;
                            $link=$productTabLink;
                            $row['erp_integration']=$productTabLink['integration'];
                            $row['erp_product_tab_observed_at']=$productTabLink['observed_at'];
                            $row['erp_product_tab_verified']=true;
                            $row['erp_gtin_differs_from_amazon']=($ean!==null&&$ean!==''
                                &&(string)$candidate['erp_gtin']!==$ean);
                        }
                    } elseif ($productTabDecision==='verified_same') {
                        $row['erp_product_tab_observed_at']=$productTabLink['observed_at'];
                        $row['erp_product_tab_verified']=true;
                    } elseif ($productTabDecision==='reference_only') {
                        $row['erp_historical_product_tab_candidate']=[
                            'erp_sku'=>$productTabLink['candidate_erp_sku']??null,
                            'observed_at'=>$productTabLink['observed_at']??null,
                            'source'=>'OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI'];
                    }
                }
                $row['erp_match_method']=$link['method'];
                $row['erp_mapping_reason']=$link['reason'];
                if ($link['status']!=='mapped' || $prod===null) {
                    $row['status']=$link['status'];
                    $rows[]=$row;
                    continue;
                }
                $kitComponents=$prod['erp_kit_components']??[];
                unset($prod['erp_kit_components']); // No raw BOM in persisted report.
                $row = self::mergeProductForOffer($row,$prod);
                if (($prod['erp_product_type']??'')==='K') {
                    // A complete Olist K is NOT multiplied again, but its
                    // recorded price history must match the verified LIVE
                    // composition. The same BOM can exist under multiple
                    // ERP records with conflicting historical kit costs.
                    $bom=SvAmazonMarginKitCost::checkBillOfMaterials(
                        (int)$prod['erp_id'],$kitComponents,
                        (float)($prod['last_cost']??0),
                        fn(int $id):array=>$this->product($id)
                    );
                    $row['erp_kit_bom_cost']=$bom['sum_cost'];
                    $row['erp_kit_bom_total_units']=$bom['total_units'];
                    if ($bom['status']!=='confirmed') {
                        $row['erp_base_cost']=$prod['last_cost'];
                        $row['last_cost']=null;
                        $row['kit_cost_multiplier']=null;
                        $row['kit_cost_basis']='OLIST_KIT_BOM_COST_UNVERIFIED';
                        $row['kit_cost_issue']=$bom['reason'];
                        $row['status']='kit_cost_unverified';
                        $rows[]=$row;
                        continue;
                    }
                }
            } catch (Throwable $e) {
                // A per-SKU mismatch is not a global failure. An account-level
                // Olist/Amazon outage or throttling IS: retry the job instead
                // of incorrectly declaring many item costs unavailable.
                if (SvAmazonMarginReadErrorPolicy::mustAbortAudit($e)) throw $e;
                $row['status']='erp_or_catalog_read_failed';
                $this->warnings[] = 'sku_read_failed:' . self::skuExcerpt($sku) . ':' . get_class($e);
                $rows[] = $row;
                continue;
            }

            // A listed Amazon offer may contain several units of an ERP
            // simple component. Multiply that VERIFIED component cost by
            // the package count before calculating taxes, COGS and profit.
            // ERP 'K' kit costs already refer to the complete kit: never
            // multiply those by their BOM quantity again. Inconclusive
            // pack identity must not publish a misleading certified margin.
            $unitCost=SvAmazonMarginKitCost::resolve(
                (string)($row['title']??''),$row);
            $row['erp_base_cost']=$unitCost['base_cost'];
            $row['kit_units_per_listing']=$unitCost['quantity'];
            $row['kit_cost_multiplier']=$unitCost['multiplier'];
            $row['kit_cost_basis']=$unitCost['basis'];
            if ($unitCost['status']!=='confirmed'
                && $unitCost['basis']!=='MISSING_ERP_COST') {
                $row['last_cost']=null;
                $row['status']='kit_cost_unverified';
                $row['kit_cost_issue']=$unitCost['reason'];
                $rows[]=$row;
                continue;
            }
            if($unitCost['status']==='confirmed') {
                $row['last_cost']=$unitCost['cost'];
                if ($unitCost['multiplier']>1) {
                    $row['last_cost_source']='OLIST_TINY_V3_COMPONENT_COST_X_VERIFIED_AMAZON_PACK_UNITS';
                }
            }
            if ($row['last_cost']===null || ($row['price']===null && $recentShipments===[])) {
                $row['status']='price_or_cost_missing';$rows[]=$row;continue;
            }
            if (!empty($row['price_divergence'])) {
                $row['status']='price_divergence';$rows[]=$row;continue;
            }

            $shipments=$recentShipments;
            $channel = $row['fulfillment_channel'];
            if (!in_array($channel,['AMAZON','MERCHANT'],true)
                && $shipments!==[]) {
                $historicalChannel=$this->historicalChannel($shipments);
                if ($historicalChannel!==null) {
                    $channel=$historicalChannel;
                    $row['historical_fulfillment_source']='AMAZON_ORDERS_V2026_FULFILLMENT';
                }
            }
            $isFba = $channel==='AMAZON';
            $isMerchant = $channel==='MERCHANT';
            if (!$isFba && !$isMerchant) {
                // There are shipments, but no verified current or historical
                // fulfillment program. Do not relabel them "no sales".
                if ($shipments!==[]) {
                    $row['pending_sales']=array_sum(array_map(
                        static fn(array $s):int=>(int)($s['source_rows']??1),$shipments));
                    $row['status']='pending_financial_reconciliation';
                } else $row['status']='fulfillment_unverified';
                $rows[]=$row;
                continue;
            }
            $row['program'] = $isFba ? 'FBA_ONSITE' : 'DBA_CANDIDATE';
            // DBA postage is debited once per order (not per Shipment row).
            // Only financial-window rows are passed to the calculation.
            if ($isMerchant) {
                $shipments=SvAmazonMarginDbaOrderGroup::combine($shipments);
            }
            $margin = [];
            $totalProfit = 0;
            $totalGross = 0;
            $feesAssessedByOrder = [];
            foreach ($shipments as $shipment) {
                $sourceRows=(int)($shipment['source_rows']??1);
                // Older orders are identity evidence ONLY, not financial results.
                if (!SvAmazonMarginOlistOrderLink::inFinancialWindow((string)($shipment['posted_at']??''),$postedAfter))continue;
                $order = $shipment['order'];
                $postage = null;
                $confirmed = false;
                if ($isMerchant) {
                    try { $dba = $this->orderIsDba($order); }
                    catch (Throwable $e) {
                        if (SvAmazonMarginReadErrorPolicy::mustAbortAudit($e)) throw $e;
                        $dba = null;
                    }
                    if ($dba !== true) { $row['pending_sales']+=$sourceRows;continue; }
                    $row['program']='DBA';
                    $onlySku = count($fin['order_skus'][$order] ?? [])===1;
                    $isFirst = !isset($feesAssessedByOrder[$order]);
                    $charges = $fin['fees'][$order] ?? [];
                    if ($onlySku && $isFirst && $charges!==[]) {
                        $postage = array_sum($charges);
                        $confirmed=true;
                        $feesAssessedByOrder[$order]=true;
                    }
                } else {
                    $postage = 0;$confirmed=true;
                }
                $calc = SvAmazonMarginMath::calculate([
                    'sku'=>$sku,'program'=>$isFba?'FBA_ONSITE':'DBA',
                    'price'=>$row['price'],'cost'=>$row['last_cost'],
                    'quantity'=>$shipment['quantity'],'shipment_total'=>$shipment['net_amount'],
                    'sale_gross_cents'=>$shipment['sale_gross_cents']??null,
                    'postage_total'=>$postage,'postage_confirmed'=>$confirmed,'tax_bps'=>$taxBps,
                ]);
                if ($calc['status']==='confirmed') {
                    $margin[] = $calc['margin_bps'];
                    $totalProfit += $calc['profit_cents'];
                    $totalGross += $calc['gross_cents'];
                    $row['confirmed_sales']+=$sourceRows;
                    $row['confirmed_units'] += $shipment['quantity'];
                } else $row['pending_sales']+=$sourceRows;
            }
            if ($margin) {
                $row['margin_min_bps']=min($margin);
                $row['margin_max_bps']=max($margin);
                $row['margin_weighted_bps']=(int)round($totalProfit*10000/$totalGross);
                $row['profit_cents']=$totalProfit;
                $row['realized_sale_gross_cents']=$totalGross;
                $row['realized_unit_price']=round($totalGross/100/$row['confirmed_units'],2);
                $row['financial_gross_source']='AMAZON_FINANCES_PRODUCT_CHARGES_PLUS_NET_SHIPPING';
                $row['status']=SvAmazonMarginFinancialStatus::classify(
                    (int)$row['confirmed_sales'], (int)$row['pending_sales']);
                $row['below_10_pct']=$row['margin_min_bps']<1000;
            } else {
                $row['status']=SvAmazonMarginFinancialStatus::classify(
                    (int)$row['confirmed_sales'], (int)$row['pending_sales']);
                $row['below_10_pct'] = null;
            }
            $rows[]=$row;
        }

        // Keep transactional margins untouched; quote a second, explicitly
        // estimated current-price metric even when there were no recent sales.
        $this->note('quoting_current_fees',0,count($rows));
        $feeQuotes=SvAmazonCurrentFeeEstimates::quote($this->amazon,$rows,$this->marketplace,
            fn(int $done,int $total)=>$this->note('quoting_current_fees',$done,$total));
        $region=trim((string)(getenv('SV_AMAZON_DBA_ORIGIN_REGION')?:''));
        foreach($rows as &$pricedRow){
            $sku=(string)($pricedRow['seller_sku']??'');
            $pricedRow+=SvAmazonCurrentPriceMargin::calculate($pricedRow,
                $feeQuotes[$sku]??null,$taxBps,$region!==''?$region:null);
        }
        unset($pricedRow);
        $this->note('saving_report',count($rows),count($listings));
        usort($rows, static function($a,$b): int {
            $ap=$a['margin_min_bps']??PHP_INT_MAX;$bp=$b['margin_min_bps']??PHP_INT_MAX;
            return $ap<=>$bp ?: strcmp((string)$a['seller_sku'],(string)$b['seller_sku']);
        });
        return [
            'generated_at'=>gmdate('c'),
            'period_from'=>$postedAfter,
            'scope'=>$selectedSkus?'selected':'all',
            'sources'=>['AMAZON_SPAPI_LISTINGS','AMAZON_SPAPI_PRICING',
                'AMAZON_SPAPI_FINANCES','AMAZON_SPAPI_ORDERS','OLIST_TINY_V3','OLIST_V3_AMAZON_ORDER_ID_JOIN','OLIST_ERP_ANUNCIOS_EXPORT',
                'OLIST_PRODUCT_TYPE_AND_AMAZON_LISTING_KIT_QUANTITY',
                'OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI'],
            'tax_bps'=>$taxBps,
            'offer_count'=>count($rows),
            'warnings'=>$this->warnings,
            'rows'=>$rows,
        ];
    }
}
