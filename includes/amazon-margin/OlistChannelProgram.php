<?php
declare(strict_types=1);

/**
 * Current Olist product->Anuncios Amazon integration establishes the commercial
 * logistics program only when the CURRENT Amazon offer's fulfillment channel
 * corroborates it. This is a read-only classification, never a listing change.
 */
final class SvAmazonMarginOlistChannelProgram
{
    public static function resolve(?string $integration, ?string $fulfillmentChannel): ?string
    {
        $integration=trim((string)$integration);
        $fulfillmentChannel=strtoupper(trim((string)$fulfillmentChannel));
        if ($integration==='Amazon' && $fulfillmentChannel==='MERCHANT') return 'DBA';
        if ($integration==='Amazon Onsite' && $fulfillmentChannel==='AMAZON') return 'FBA_ONSITE';
        return null;
    }
}
