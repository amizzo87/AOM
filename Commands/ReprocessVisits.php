<?php
/**
 * AOM - Piwik Advanced Online Marketing Plugin
 *
 * @author Daniel Stonies <daniel.stonies@googlemail.com>
 */
namespace Piwik\Plugins\AOM\Commands;

use Monolog\Logger;
use Piwik\Common;
use Piwik\Db;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugin\Manager;
use Piwik\Plugins\AOM\AOM;
use Piwik\Plugins\AOM\Platforms\PlatformInterface;
use Piwik\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Piwik\Plugins\SitesManager\API as APISitesManager;

/**
 * Example:
 * ./console aom:reprocess-visits --startDate=2016-12-01 --endDate=2016-12-01
 */
class ReprocessVisits extends ConsoleCommand
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param string|null $name
     * @param LoggerInterface|null $logger
     */
    public function __construct($name = null, LoggerInterface $logger = null)
    {
        $this->logger = AOM::getTasksLogger();

        parent::__construct($name);
    }

    /**
     * Convenience function for shorter logging statements
     *
     * @param string $logLevel
     * @param string $message
     * @param array $additionalContext
     */
    protected function log($logLevel, $message, $additionalContext = [])
    {
        $this->logger->log(
            $logLevel,
            $message,
            array_merge(['task' => 'reprocess-visits'], $additionalContext)
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!in_array('AdvancedCampaignReporting', Manager::getInstance()->getInstalledPluginsName())) {
            $this->log(Logger::ERROR, 'Plugin "AdvancedCampaignReporting" must be installed and activated.');
            exit;
        }

        foreach (AOM::getPeriodAsArrayOfDates($input->getOption('startDate'), $input->getOption('endDate')) as $date) {
            $this->processDate($date);
        }
    }

    protected function configure()
    {
        $this
            ->setName('aom:reprocess-visits')
            ->addOption('startDate', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD')
            ->addOption('endDate', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD')
            ->setDescription(
                'Allocates platform costs to Piwik visits and stores them in aom_visits for further processing.'
            );
    }

    /**
     * Reprocesses the visits of a specific date.
     * This method must be public so that it can be called from Tasks.php.
     *
     * @param string $date YYYY-MM-DD
     * @throws \Exception
     */
    public function processDate($date)
    {
        // Clean up reprocessed data
        // TODO: Use Platform::deleteReprocessedData($websiteId, $date) instead
        Db::deleteAllRows(Common::prefixTable('aom_visits'), 'WHERE date_website_timezone = ?', 'id', 100000, [$date,]);

        // Get visits
        $visits = $this->getVisits($date);
        $totalPiwikVisits = count($visits);


        // Process every platform
        foreach (AOM::getPlatforms() as $platformName) {

            $this->log(Logger::DEBUG, 'Processing ' . $platformName . '...');

            $platform = AOM::getPlatformInstance($platformName);

            // Get Piwik visits that came from this platform and that we have assigned to this platform
            $platformVisits = array_filter($visits, function($visit) use ($platformName) {
                return ($visit['aom_platform'] == $platformName);
            });

            // Get platform costs
            $costs = $this->getPlatformCosts($platform, $date);

            $clicksAccordingToPlatform = 0;
            $clicksAccordingToPlatformUnmerged = 0;
            $totalMatchingVisits = 0;
            $totalCreatedVisits = 0;
            $totalCosts = 0;

            foreach ($costs as $cost) {

                // Get Piwik visits for this specific costs
                $matchingVisits = array_filter($platformVisits, function($visit) use ($cost) {
                    return ($visit['aom_platform_row_id'] == $cost['id']);
                });

                if (count($matchingVisits) > 0) {

                    // Add matched Piwik visits with calculated CPC and platform data to database
                    foreach ($matchingVisits as $matchingVisit) {
                        $this->addVisit(
                            [
                                'siteId' => $cost['idsite'],
                                'visitId' => $matchingVisit['idvisit'],
                                'visitorId' => $matchingVisit['idvisitor'],
                                'firstActionTimeUTC' => $matchingVisit['visit_first_action_time'],
                                'dateWebsiteTimezone' => $date,
                                'channel' => $this->determineChannel(
                                    $platformName,
                                    $matchingVisit['referer_type'],
                                    $matchingVisit['campaign_name']
                                ),
                                'campaignData' => $matchingVisit['campaign_data'],
                                'platformData' => json_encode($cost),
                                'cost' => ($cost['cost'] / count($matchingVisits)), // Calculate CPC based on Piwik visits
                                'conversions' => $matchingVisit['conversions'],
                                'revenue' => $matchingVisit['revenue'],
                            ]
                        );

                        $totalMatchingVisits++;
                    }

                    // Remove matching visits from $visits
                    $visits = array_filter($visits, function($visit) use ($cost) {
                        return ($visit['aom_platform_row_id'] != $cost['id']);
                    });

                } else {

                    // Costs but no match -> create visit (so that total costs will match)
                    $this->addVisit(
                        [
                            'siteId' => $cost['idsite'],
                            'firstActionTimeUTC' => AOM::convertLocalDateTimeToUTC(
                                $date . ' 00:00:00',
                                Site::getTimezoneFor($cost['idsite'])
                            ),
                            'dateWebsiteTimezone' => $date,
                            'channel' => $this->determineChannel($platformName),
                            'platformData' => json_encode($cost),
                            'cost' => $cost['cost'],
                        ]
                    );

                    $totalCreatedVisits++;
                    $clicksAccordingToPlatformUnmerged += $cost['clicks'];
                }

                $clicksAccordingToPlatform += $cost['clicks'];
                $totalCosts += $cost['cost'];
            }


            // Platform stats
            $this->log(
                Logger::DEBUG,
                sprintf(
                    '%d Piwik %s visits (based on aom_platform) resulted in %d %s visits.',
                    count($platformVisits),
                    $platformName,
                    ($totalMatchingVisits + $totalCreatedVisits),
                    $platformName
                )
            );
            $this->log(
                Logger::DEBUG,
                sprintf(
                    '%s reported %d records (with at least 1 click or cost > 0) with %d clicks.',
                    $platformName,
                    count($costs),
                    $clicksAccordingToPlatform
                )
            );
            $this->log(
                Logger::DEBUG,
                sprintf(
                    '%d Piwik visits matched (based on aom_platform_row_id), but %d visits had to be created for %d unmerged clicks.',
                    $totalMatchingVisits,
                    $totalCreatedVisits,
                    $clicksAccordingToPlatformUnmerged
                )
            );
            $this->log(
                Logger::DEBUG,
                sprintf(
                    'Total costs reported vs. total costs of all resulting visits are %f vs. %f.',
                    $totalCosts,
                    Db::fetchOne(
                        'SELECT SUM(cost) FROM ' . Common::prefixTable('aom_visits')
                        . ' WHERE date_website_timezone = ? AND channel = ?',
                        [
                            $date,
                            $platformName,
                        ]
                    )
                )
            );
        }

        // Add remaining Piwik visits (visits without aom_platform_row_id) to aom_visits.
        $this->log(
            Logger::DEBUG,
            'Will add ' . count($visits) . ' remaining visits now (Piwik visits without aom_platform_row_id).'
        );

        foreach ($visits as $visit) {
            $this->addVisit(
                [
                    'siteId' => $visit['idsite'],
                    'visitId' => $visit['idvisit'],
                    'visitorId' => $visit['idvisitor'],
                    'firstActionTimeUTC' => $visit['visit_first_action_time'],
                    'dateWebsiteTimezone' => $date,
                    'channel' => $this->determineChannel(null, $visit['referer_type'], $visit['campaign_name']),
                    'campaignData' => $visit['campaign_data'],
                    'conversions' => $visit['conversions'],
                    'revenue' => $visit['revenue'],
                ]
            );
        }

        // Final stats
        $totalResultingVisits = Db::fetchOne(
            'SELECT COUNT(*) FROM ' . Common::prefixTable('aom_visits') . ' WHERE date_website_timezone = ?',
            [$date,]
        );
        $this->log(
            Logger::DEBUG,
            'Reprocessed ' . $totalPiwikVisits . ' Piwik visits to ' . $totalResultingVisits . " visits ({$date})."
        );
    }


    /**
     * Returns the platform's costs for the given date.
     *
     * @param PlatformInterface $platform
     * @param string $date YYYY-MM-DD
     * @return array
     */
    private function getPlatformCosts(PlatformInterface $platform, $date)
    {
        return Db::fetchAll(
            'SELECT * FROM ' . $platform->getDataTableName()
                . ' WHERE date = ? AND (clicks > 0 OR cost > 0)',   // Some platform's might create irrelevant rows!
            [
                $date,
            ]
        );
    }

    /**
     * Determines the channel based on various tracking information.
     *
     * @param null|string $platform
     * @param string $refererType
     * @param null|string $campaignName
     * @return null|string
     */
    private function determineChannel($platform, $refererType = null, $campaignName = null)
    {
        if (null !== $platform) {
            return $platform;
        }

        // TODO: Return white-listed campaign names when $refererType is "campaign"?!

        return $refererType;
    }

    /**
     * Stores a visit with allocated costs and various platform data in aom_visits.
     *
     * TODO: Collect queries and perform multiple queries at once to improve performance.
     *
     * @param array $visit The following information about the visit:
     *   string $visitId Piwik visit ID / Manually created visits must create consistent keys from the same raw data
     *   string $visitorId Piwik visitor ID
     *   string $firstActionTimeUTC The UTC datetime of the visit's first action
     *   string $dateWebsiteTimezone The date in the website's timezone
     *   string|null $channel The platform (or source) this visit came from (can be null)
     *   array|null $campaignData
     *   string|null $platformData JSON platform data (can be null)
     *   float|null $cost The price paid to the advertising platform for this specific visit
     *   int|null $conversions Number of conversions the visit created
     *   float|null $revenue Total revenue of conversions the visit created
     */
    private function addVisit(array $visit)
    {
        // TODO: Mixing FK with uniqid() might not be good, but it seems we need a unique visitor Id somewhere?!
        $visitorId =  array_key_exists('visitorId', $visit) ? $visit['visitorId'] : uniqid();

        $channel = array_key_exists('channel', $visit) ? $visit['channel'] : null;
        $platformData = array_key_exists('platformData', $visit) ? $visit['platformData'] : null;

        // We must avoid having the same record multiple times in this table, e.g. when this command is being executed
        // in parallel. For regular piwik visits we can use piwik_idvisit as a unique hash. Manually created visits must
        // make sure that they create consistent unique hashed from the same raw data.
        $uniqueHash = array_key_exists('visitId', $visit)
            ? 'piwik-visit-' . $visit['visitId']
            : $visit['dateWebsiteTimezone'] . '-' . $channel . '-' . hash('md5', $platformData);

        // Allow adding additional data to campaign_data
        if (is_file(PIWIK_DOCUMENT_ROOT . '/plugins/AOM/custom.php')) {
            include PIWIK_DOCUMENT_ROOT . '/plugins/AOM/custom.php';
        }

        Db::query(
            'INSERT INTO ' . Common::prefixTable('aom_visits')
                . ' (idsite, piwik_idvisit, piwik_idvisitor, unique_hash, first_action_time_utc, date_website_timezone,  
                     channel, campaign_data, platform_data, cost, conversions, revenue, ts_created) '
                . 'VALUE (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $visit['siteId'],
                array_key_exists('visitId', $visit) ? $visit['visitId'] : null,
                $visitorId,
                $uniqueHash,
                $visit['firstActionTimeUTC'],
                $visit['dateWebsiteTimezone'],
                $channel,
                array_key_exists('campaignData', $visit) ? json_encode($visit['campaignData']) : null,
                $platformData,
                array_key_exists('cost', $visit) ? $visit['cost'] : null,
                array_key_exists('conversions', $visit) ? $visit['conversions'] : null,
                array_key_exists('revenue', $visit) ? $visit['revenue'] : null,
            ]
        );
    }

    /**
     * Returns the visits of all websites that occurred at the given date based on the website's timezone.
     *
     * @param string $date YYYY-MM-DD
     * @return array
     * @throws \Exception
     */
    private function getVisits($date)
    {
        // We assume that the website's timezone matches the timezone of all advertising platforms.
        $visits = [];

        // We get visits per website to consider the website's individual timezones.
        foreach (APISitesManager::getInstance()->getAllSites() as $site) {
            $visits = array_merge(
                $visits,
                Db::fetchAll(
                    'SELECT
                            v.idvisit AS idvisit,
                            conv(hex(v.idvisitor), 16, 10) AS idvisitor,
                            v.idsite AS idsite,
                            v.visit_first_action_time,
                            CASE v.referer_type
                                WHEN 1 THEN "direct"
                                WHEN 2 THEN "search_engine"
                                WHEN 3 THEN "website"
                                WHEN 6 THEN "campaign"
                                ELSE ""
                            END AS referer_type,
                            v.referer_name,
                            v.referer_keyword,
                            v.referer_url,
                            v.campaign_name,
                            v.campaign_keyword,
                            v.campaign_source,
                            v.campaign_medium,
                            v.campaign_content,
                            v.campaign_id,
                            v.aom_platform,
                            v.aom_platform_row_id AS aom_platform_row_id,
                            COUNT(c.idorder) AS conversions,
                            SUM(c.revenue) AS revenue
                        FROM ' . Common::prefixTable('log_visit') . '  AS v 
                        LEFT JOIN ' . Common::prefixTable('log_conversion') . ' AS c ON v.idvisit = c.idvisit
                        WHERE v.idsite = ? AND v.visit_first_action_time >= ? AND v.visit_first_action_time <= ?
                        GROUP BY v.idvisit',
                    [
                        $site['idsite'],
                        AOM::convertLocalDateTimeToUTC($date . ' 00:00:00', $site['timezone']),
                        AOM::convertLocalDateTimeToUTC($date . ' 23:59:59', $site['timezone']),
                    ]
                )
            );
        }

        foreach ($visits as &$visit) {
            $visit['campaign_data'] = [];
            if (null !== $visit['campaign_name']) {
                $visit['campaign_data']['campaignName'] = $visit['campaign_name'];
            }
            if (null !== $visit['campaign_keyword']) {
                $visit['campaign_data']['campaignKeyword'] = $visit['campaign_keyword'];
            }
            if (null !== $visit['campaign_source']) {
                $visit['campaign_data']['campaignSource'] = $visit['campaign_source'];
            }
            if (null !== $visit['campaign_medium']) {
                $visit['campaign_data']['campaignMedium'] = $visit['campaign_medium'];
            }
            if (null !== $visit['campaign_content']) {
                $visit['campaign_data']['campaignContent'] = $visit['campaign_content'];
            }
            if (null !== $visit['campaign_id']) {
                $visit['campaign_data']['campaignId'] = $visit['campaign_id'];
            }
            if (null !== $visit['referer_name']) {
                $visit['campaign_data']['refererName'] = $visit['referer_name'];
            }
            if (null !== $visit['referer_url']) {
                $visit['campaign_data']['refererUrl'] = $visit['referer_url'];
            }
        }

        $this->log(
            Logger::DEBUG,
            'Got ' . count($visits) . ' Piwik visits with '
            . array_sum(array_map(function($visit) { return $visit['conversions']; }, $visits)) . ' conversions for '
            . $date . '.'
        );

        return $visits;
    }
}
