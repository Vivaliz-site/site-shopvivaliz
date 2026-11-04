<?php
declare(strict_types=1);

require_once __DIR__ . '/MarginMath.php';
require_once __DIR__ . '/ProductMatch.php';
require_once __DIR__ . '/RateLimitPolicy.php';

/**
 * Read-only Amazon SP-API + Olist/Tiny v3 collection.
 * Never creates or updates products, listings or prices.
 * Store only SKU-level financial summaries, not customer/order PII.
 */
final class SvAmazonMarginCollector
{
    private array $productsByEan = [];
    private array $productsBySku = [];
    private array $productDetails = [];
    private array $eanByAsin = [];
    private array $programByOrder = [];
    private array $warnings = [];
    private $onProgress = null;
    private string $marketplace;

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
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $ch = curl_init('https://api.tiny.com.br/public-api/v3' . $path);
            if ($ch === false) throw new RuntimeException('tiny_transport_error');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->tinyToken, 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 35,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 429 && $attempt < 2) { sleep(2 + $attempt * 3); continue; }
            if ($status !== 200 || !is_string($body)) {
                throw new RuntimeException('tiny_http_' . $status);
            }
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('tiny_invalid_response');
            return $decoded;
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
            if (!isset($seen[$sku])) $this->warnings[] = 'not_found_listing:' . substr($sku, 0, 80);
        }
        return $seen;
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
                $offers = $r['Product']['Offers'] ?? [];
                $offer = $offers[0] ?? [];
                $live = self::validPrice($offer['BuyingPrice']['ListingPrice']['Amount'] ?? null);
                $listings[$sku]['price'] = $live;
                $listings[$sku]['price_source'] = 'AMAZON_SPAPI_PRICING';
                $listings[$sku]['fulfillment_channel'] = (string)($offer['FulfillmentChannel'] ?? 'UNKNOWN');
                if ($live !== null && $listings[$sku]['listed_price'] !== null
                    && abs($live - $listings[$sku]['listed_price']) >= 0.01) {
                    $listings[$sku]['price_divergence'] = true;
                }
                $seen[$sku] = true;
            }
            foreach ($chunk as $sku) {
                if (!isset($seen[$sku])) {
                    $listings[$sku]['price'] = null;
                    $listings[$sku]['price_source'] = 'UNVERIFIED';
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
            'marketplaceIds'=>$this->marketplace,'includedData'=>'identifiers',
        ]);
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
        ];
    }

    /** @return array{shipments:array,fees:array,order_skus:array} */
    private function finances(string $postedAfter): array
    {
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
                if ($type === 'ServiceFee' && ($t['description'] ?? '') === 'MfnPostageFee' && $order !== '') {
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

    private function orderIsDba(string $order): ?bool
    {
        if ($order === '') return null;
        if (array_key_exists($order,$this->programByOrder)) return $this->programByOrder[$order];
        $res = $this->a('GET','/orders/2026-01-01/orders/'.rawurlencode($order),['includedData'=>'FULFILLMENT']);
        $o = $res['order'] ?? $res['payload'] ?? $res;
        $programs = $o['programs'] ?? [];
        $yes = is_array($programs) ? in_array('DELIVERY_BY_AMAZON',$programs,true) : false;
        $this->programByOrder[$order] = $yes;
        usleep(280000);
        return $yes;
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
        $this->note('reading_transactions');
        $fin = $this->finances($postedAfter);
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
            $row += ['ean'=>null, 'erp_id'=>null, 'erp_sku'=>null,'last_cost'=>null,
                'last_cost_date'=>null,'margin_min_bps'=>null,'margin_max_bps'=>null,
                'margin_weighted_bps'=>null,'confirmed_sales'=>0,'pending_sales'=>0,
                'confirmed_units'=>0,'profit_cents'=>0,'status'=>'unverified'];
            $asin = $row['asin'];
            try {
                $ean = $this->ean($asin);
                $row['ean'] = $ean;
                $link = SvAmazonMarginProductMatch::resolve($sku,$ean,
                    $this->productsByEan,$this->productsBySku);
                $row['erp_match_method'] = $link['method'];
                $row['erp_mapping_reason'] = $link['reason'];
                if ($link['status'] !== 'mapped') {
                    $row['status'] = $link['status'];
                    $rows[] = $row;
                    continue;
                }
                $prod = $this->product((int)$link['id']);
                if (!$prod['active'] || ($ean!==null&&$ean!==''&&$prod['erp_gtin']!==$ean)
                    || ($link['method']==='SELLER_SKU_EXACT'
                        && strcasecmp($prod['erp_sku'],(string)$sku)!==0)) {
                    $row['status'] = 'erp_identity_mismatch';
                    $row['erp_mapping_reason']='product_detail_identity_mismatch';
                    $rows[] = $row;
                    continue;
                }
                $row = array_merge($row,$prod);
            } catch (Throwable $e) {
                $row['status']='erp_or_catalog_read_failed';
                $this->warnings[] = 'sku_read_failed:' . substr($sku,0,80) . ':' . get_class($e);
                $rows[] = $row;
                continue;
            }

            if ($row['price']===null || $row['last_cost']===null) {
                $row['status']='price_or_cost_missing';$rows[]=$row;continue;
            }
            if (!empty($row['price_divergence'])) {
                $row['status']='price_divergence';$rows[]=$row;continue;
            }

            $channel = $row['fulfillment_channel'];
            $isFba = $channel==='AMAZON';
            $isMerchant = $channel==='MERCHANT';
            if (!$isFba && !$isMerchant) {
                $row['status']='fulfillment_unverified';$rows[]=$row;continue;
            }
            $row['program'] = $isFba ? 'FBA_ONSITE' : 'DBA_CANDIDATE';
            $margin = [];
            $totalProfit = 0;
            $totalGross = 0;
            $feesAssessedByOrder = [];
            foreach (($fin['shipments'][$sku] ?? []) as $shipment) {
                $order = $shipment['order'];
                $postage = null;
                $confirmed = false;
                if ($isMerchant) {
                    try { $dba = $this->orderIsDba($order); }
                    catch (Throwable $e) { $dba = null; }
                    if ($dba !== true) { $row['pending_sales']++;continue; }
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
                    'postage_total'=>$postage,'postage_confirmed'=>$confirmed,'tax_bps'=>$taxBps,
                ]);
                if ($calc['status']==='confirmed') {
                    $margin[] = $calc['margin_bps'];
                    $totalProfit += $calc['profit_cents'];
                    $totalGross += $calc['gross_cents'];
                    $row['confirmed_sales']++;
                    $row['confirmed_units'] += $shipment['quantity'];
                } else $row['pending_sales']++;
            }
            if ($margin) {
                $row['margin_min_bps']=min($margin);
                $row['margin_max_bps']=max($margin);
                $row['margin_weighted_bps']=(int)round($totalProfit*10000/$totalGross);
                $row['profit_cents']=$totalProfit;
                $row['status']= $row['pending_sales'] ? 'partial' : 'confirmed';
                $row['below_10_pct']=$row['margin_min_bps']<1000;
            } else {
                $row['status'] = 'no_confirmed_sales';
                $row['below_10_pct'] = null;
            }
            $rows[]=$row;
        }

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
                'AMAZON_SPAPI_FINANCES','AMAZON_SPAPI_ORDERS','OLIST_TINY_V3'],
            'tax_bps'=>$taxBps,
            'offer_count'=>count($rows),
            'warnings'=>$this->warnings,
            'rows'=>$rows,
        ];
    }
}
