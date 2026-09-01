<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/marketplace/AmazonClient.php';

final class SvAmazonRecoveryReports
{
    public function __construct(private SvAmazonApi $api) {}

    public function requestReturnReport(string $reportType,DateTimeImmutable $start,DateTimeImmutable $end): string
    {
        $allowed=['GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE','GET_XML_RETURNS_DATA_BY_RETURN_DATE','GET_FBA_FULFILLMENT_CUSTOMER_RETURNS_DATA','GET_FBA_REIMBURSEMENTS_DATA'];
        if (!in_array($reportType,$allowed,true)) throw new InvalidArgumentException('Unsupported Amazon recovery report type.');
        $response=$this->api->request('POST','/reports/2021-06-30/reports',[],['reportType'=>$reportType,'dataStartTime'=>$start->format(DATE_ATOM),'dataEndTime'=>$end->format(DATE_ATOM),'marketplaceIds'=>[$this->api->marketplaceId()]]);
        $reportId=trim((string)($response['data']['reportId'] ?? ''));
        if ($reportId==='') throw new RuntimeException('Amazon Reports API did not return reportId.');
        return $reportId;
    }

    /** @return array<string,mixed> */
    public function getReport(string $reportId): array { return $this->api->request('GET','/reports/2021-06-30/reports/'.rawurlencode($reportId))['data']; }
    /** @return array<string,mixed> */
    public function getReportDocument(string $documentId): array { return $this->api->request('GET','/reports/2021-06-30/documents/'.rawurlencode($documentId))['data']; }
}
