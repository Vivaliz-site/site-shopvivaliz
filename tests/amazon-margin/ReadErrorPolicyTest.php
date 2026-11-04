<?php
declare(strict_types=1);
if (!class_exists('SvMarketplaceException',false)) {
    class SvMarketplaceException extends RuntimeException
    {
        public function __construct(public int $httpStatus)
        {
            parent::__construct('amazon_http_'.$httpStatus);
        }
    }
}
require_once __DIR__.'/../../includes/amazon-margin/ReadErrorPolicy.php';
$global=['tiny_http_0','tiny_http_401','tiny_http_403','tiny_http_429',
         'tiny_http_500','tiny_http_502','tiny_http_503','tiny_http_504'];
foreach($global as $code) {
    if (!SvAmazonMarginReadErrorPolicy::mustAbortAudit(new RuntimeException($code))) {
        throw new RuntimeException('Global ERP outage erroneously treated as a SKU failure: '.$code);
    }
}
$local=['tiny_http_404','tiny_http_422','catalog_item_not_found',
        'product_detail_identity_mismatch','tiny_invalid_response'];
foreach($local as $code) {
    if (SvAmazonMarginReadErrorPolicy::mustAbortAudit(new RuntimeException($code))) {
        throw new RuntimeException('Non-global SKU error must be isolated: '.$code);
    }
}
foreach([401,403,429,500,502,503,504] as $code) {
    if(!SvAmazonMarginReadErrorPolicy::mustAbortAudit(new SvMarketplaceException($code)))
        throw new RuntimeException('Missing Amazon provider global failure: '.$code);
}
foreach([200,400,404,422] as $code) {
    if(SvAmazonMarginReadErrorPolicy::mustAbortAudit(new SvMarketplaceException($code)))
        throw new RuntimeException('Amazon SKU error incorrectly escalated: '.$code);
}
echo "PASS merchant provider failure classifier: vendor quota/outage aborts; SKU-specific lookup isolated\n";
