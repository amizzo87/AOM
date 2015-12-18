<?php
/**
 * AOM - Piwik Advanced Online Marketing Plugin
 *
 * @author Daniel Stonies <daniel.stonies@googlemail.com>
 */
namespace Piwik\Plugins\AOM\Platforms\Criteo;

use Exception;
use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\AOM\Platforms\PlatformInterface;
use Piwik\Plugins\AOM\Settings;
use SoapClient;
use SoapFault;
use SoapHeader;

class Criteo implements PlatformInterface
{
    /**
     * @var  Settings
     */
    private $settings;

    public function __construct()
    {
        $this->settings = new Settings();
    }

    public function isActive()
    {
        return $this->settings->criteoIsActive->getValue();
    }

    public function activatePlugin()
    {
        try {
            $sql = 'CREATE TABLE ' . Common::prefixTable('aom_criteo') . ' (
                        date DATE NOT NULL,
                        campaign_id INTEGER NOT NULL,
                        campaign VARCHAR(255) NOT NULL,
                        impressions INTEGER NOT NULL,
                        clicks INTEGER NOT NULL,
                        cost FLOAT NOT NULL,
                        conversions INTEGER NOT NULL,
                        conversions_value FLOAT NOT NULL,
                        conversions_post_view INTEGER NOT NULL,
                        conversions_post_view_value FLOAT NOT NULL
                    )  DEFAULT CHARSET=utf8';
            Db::exec($sql);
        } catch (\Exception $e) {
            // ignore error if table already exists (1050 code is for 'table already exists')
            if (!Db::get()->isErrNo($e, '1050')) {
                throw $e;
            }
        }

        try {
            $sql = 'CREATE INDEX index_aom_criteo ON ' . Common::prefixTable('aom_criteo')
                . ' (date, campaign_id)';  // TODO...
            Db::exec($sql);
        } catch (\Exception $e) {
            // ignore error if index already exists (1061)
            if (!Db::get()->isErrNo($e, '1061')) {
                throw $e;
            }
        }
    }

    public function uninstallPlugin()
    {
        Db::dropTables(Common::prefixTable('aom_criteo'));
    }

    public function import($startDate, $endDate)
    {
        if (!$this->isActive()) {
            return;
        }

        // Delete existing data for the specified period
        // TODO: this might be more complicated when we already merged / assigned data to visits!?!
        // TODO: There might be more than 100000 rows?!
        Db::deleteAllRows(
            Common::prefixTable('aom_criteo'),
            'WHERE date >= ? AND date <= ?',
            'date',
            100000,
            [$startDate, $endDate]
        );

        $soapClient = new SoapClient('https://advertising.criteo.com/api/v201010/advertiserservice.asmx?WSDL', [
            'soap_version' => SOAP_1_2,
            'exceptions' => true,
            'trace' => 0,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);

        $clientLogin = new \stdClass();
        $clientLogin->username = $this->settings->criteoUsername->getValue();
        $clientLogin->password = $this->settings->criteoPassword->getValue();

        // TODO: Use multiple try catch blocks instead?!
        try {
            $loginResponse = $soapClient->__soapCall('clientLogin', [$clientLogin]);

            $apiHeader = new \stdClass();
            $apiHeader->appToken = $interval = $this->settings->criteoAppToken->getValue();
            $apiHeader->authToken = $loginResponse->clientLoginResult;

            // Create Soap Header, then set the Headers of Soap Client.
            $soapHeader = new SoapHeader('https://advertising.criteo.com/API/v201010', 'apiHeader', $apiHeader, false);
            $soapClient->__setSoapHeaders([$soapHeader]);

            // Get names of campaigns
            $campaigns = [];
            $getCampaignsParameters = new \stdClass();
            $getCampaignsParameters->campaignSelector = new \stdClass();
            $getCampaignsParameters->campaignSelector->campaignStatus = ['RUNNING', 'NOT_RUNNING'];
            $result = $soapClient->__soapCall('getCampaigns', [$getCampaignsParameters]);
            if (!is_array($result->getCampaignsResult->campaign)) {
                return;
            }
            foreach ($result->getCampaignsResult->campaign as $campaign) {
                $campaigns[$campaign->campaignID] = $campaign->campaignName;
            }

            // Schedule report for date range
            $scheduleReportJobParameters = new \stdClass();
            $scheduleReportJobParameters->reportJob = new \stdClass();
            $scheduleReportJobParameters->reportJob->reportSelector = new \stdClass();
            $scheduleReportJobParameters->reportJob->reportType = 'Campaign';
            $scheduleReportJobParameters->reportJob->aggregationType = 'Daily';
            $scheduleReportJobParameters->reportJob->startDate = $startDate;
            $scheduleReportJobParameters->reportJob->endDate = $endDate;
            $scheduleReportJobParameters->reportJob->selectedColumns = [];
            $scheduleReportJobParameters->reportJob->isResultGzipped = false;
            $result = $soapClient->__soapCall('scheduleReportJob', [$scheduleReportJobParameters]);
            $jobId = $result->jobResponse->jobID;

            // Wait until report has been completed
            $jobIsPending = true;
            while (true === $jobIsPending) {
                $getJobStatus = new \stdClass();
                $getJobStatus->jobID = $jobId;
                $result = $soapClient->__soapCall('getJobStatus', [$getJobStatus]);
                if ('Completed' === $result->getJobStatusResult) {
                    $jobIsPending = false;
                } else {
                    sleep(5);
                }
            }

            // Download report
            $getReportDownloadUrl = new \stdClass();
            $getReportDownloadUrl->jobID = $jobId;
            $result = $soapClient->__soapCall('getReportDownloadUrl', [$getReportDownloadUrl]);
            $xmlString = file_get_contents($result->jobURL);
            $xml = simplexml_load_string($xmlString);

            // TODO: Use MySQL transaction to improve performance!
            foreach ($xml->table->rows->row as $row) {
                 Db::query(
                    'INSERT INTO ' . Common::prefixTable('aom_criteo') . ' (date, campaign_id, campaign, '
                    . 'impressions, clicks, cost, conversions, conversions_value, conversions_post_view, '
                    . 'conversions_post_view_value) VALUE (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $row['dateTime'],
                        $row['campaignID'],
                        $campaigns[(string) $row['campaignID']],
                        $row['impressions'],
                        $row['click'],
                        $row['cost'],
                        $row['sales'],
                        $row['orderValue'],
                        $row['salesPostView'],
                        $row['orderValuePostView'],
                    ]
                );
            }

            // TODO: Improve exception handling!
        } catch (SoapFault $fault) {
            echo $fault->faultcode.'-'.$fault->faultstring;
        } catch (Exception $e) {
            echo $e->getMessage();
            echo $soapClient->__getLastResponse();
        }
    }

    /**
     * Enriches a specific visit with additional Criteo information when this visit came from Criteo.
     *
     * @param array &$visit
     * @param array $ad
     * @return array
     * @throws \Exception
     */
    public function enrichVisit(array &$visit, array $ad)
    {
        $sql = 'SELECT
                    campaign_id AS campaignId,
                    campaign,
                    (cost / clicks) AS cpc
                FROM ' . Common::prefixTable('aom_criteo') . '
                WHERE
                    date = ? AND
                    campaign_id = ?';

        $results = Db::fetchRow(
            $sql,
            [
                date('Y-m-d', strtotime($visit['firstActionTime'])),
                $ad[1],
            ]
        );

        $visit['ad'] = array_merge(['source' => 'Criteo'], $results);

        return $visit;
    }

    /**
     * Builds a string key from the ad data that has been passed via URL (as URL-encoded JSON) and is used to reference
     * explicit platform data (this key is being stored in piwik_log_visit.aom_ad_key).
     *
     * @param array $adData
     * @return mixed
     */
    public function getAdKeyFromAdData(array $adData)
    {
        // TODO: Implement me!

        return 'not implemented';
    }
}
