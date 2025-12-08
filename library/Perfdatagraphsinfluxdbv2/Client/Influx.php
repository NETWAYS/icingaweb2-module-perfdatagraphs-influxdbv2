<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Vendor\FluxCsvParser;

use Icinga\Application\Config;
use Icinga\Application\Logger;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateInterval;
use DateTime;
use Exception;

/**
 * Influx handles calling the API and returning the data.
 */
class Influx
{
    protected const BUCKET_ENDPOINT = '/api/v2/buckets';
    protected const QUERY_ENDPOINT = '/api/v2/query';

    /** @var $this \Icinga\Application\Modules\Module */
    protected $client = null;

    protected string $URL;
    protected string $org;
    protected string $bucket;
    protected string $token;
    protected string $hostnameTag;
    protected string $servicenameTag;
    protected int $maxDataPoints;

    public function __construct(
        string $baseURI,
        string $org,
        string $bucket,
        string $token,
        string $hostnameTag,
        string $servicenameTag,
        int $timeout = 10,
        int $maxDataPoints = 10000,
        bool $tlsVerify = true
    ) {
        $this->client = new Client([
            'timeout' => $timeout,
            'verify' => $tlsVerify
        ]);

        $this->URL = rtrim($baseURI, '/');

        $this->maxDataPoints = $maxDataPoints;
        $this->org = $org;
        $this->bucket = $bucket;
        $this->token = $token;
        $this->hostnameTag = $hostnameTag;
        $this->servicenameTag = $servicenameTag;
    }

    protected function generateBaseQuery(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck
    ): string {
        $q = sprintf('from(bucket: "%s")', $this->bucket);
        $q .= sprintf('|> range(start: %s)', $from);
        $q .= sprintf('|> filter(fn: (r) => r._measurement == "%s")', addslashes($checkCommand));
        $q .= sprintf('|> filter(fn: (r) => r["%s"] == "%s")', $this->hostnameTag, addslashes($hostName));
        if (!$isHostCheck) {
            $q .= sprintf('|> filter(fn: (r) => r["%s"] == "%s")', $this->servicenameTag, addslashes($serviceName));
        }

        return $q;
    }

    protected function generateBaseRequest(string $q): array
    {
        $query = [
            'stream' => true,
            'headers' => [
                'Authorization' => 'Token ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/csv',
            ],
            'query' => [
                'org' => $this->org,
            ],
            'json' => [
                'query' => $q,
                'type' => 'flux',
                'dialect' => [
                    'header' => true,
                    'delimiter' => ',',
                    'annotations' => ['datatype', 'group', 'default'],
                    'commentPrefix' => '#'
                ]
            ],
        ];

        return $query;
    }

    public function getMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
    ): Response {
        $counts = $this->getMetricCount(
            $hostName,
            $serviceName,
            $checkCommand,
            $from,
            $isHostCheck
        );

        $q = $this->generateBaseQuery($hostName, $serviceName, $checkCommand, $from, $isHostCheck);
        // If there are no warn/crit values we still want to set empty values to have consistency
        $q .= '|> map(fn: (r) => ({r with warn: if exists r.warn then r.warn else "", crit: if exists r.crit then r.crit else ""}))';

        // When we have more than the max datapoints, we add the aggregateWindow function to downsample data
        if ($this->maxDataPoints > 0) {
            $windowEverySeconds = $this->getAggregateWindow($from, $counts);
            if ($windowEverySeconds > 0) {
                $q .= sprintf('|> aggregateWindow(fn: last, every: %ss)', $windowEverySeconds);
            }
        }

        // Pivot just to that we have less work transforming the data later
        $q .= '|> pivot(rowKey:["_time"], columnKey: ["_field"], valueColumn: "_value")';
        $q .= '|> sort(columns: ["_time"])';
        $q .= '|> keep(columns: ["_time", "value", "warn", "crit", "unit", "host", "service", "metric"])';

        $query = $this->generateBaseRequest($q);

        $url = $this->URL . $this::QUERY_ENDPOINT;

        Logger::debug('Calling query API at %s with query: %s', $url, $query);

        $response = $this->client->request('POST', $url, $query);

        return $response;
    }

    /**
     * status calls the Influx HTTP API to determine if Influx is reachable.
     * We use this to validate the configuration and if the API is reachable.
     *
     * @return array
     */
    public function status(): array
    {
        $query = [
            'query' => [
                'name' => $this->bucket,
            ],
            'headers' => [
                'Authorization' => 'Token ' . $this->token,
                'Content-Type' => 'application/json',
            ]
        ];

        $url = $this->URL . $this::BUCKET_ENDPOINT;

        try {
            $response = $this->client->request('GET', $url, $query);

            return ['output' =>  $response->getBody()->getContents()];
        } catch (ConnectException $e) {
            return ['output' => 'Connection error: ' . $e->getMessage(), 'error' => true];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return ['output' => 'HTTP error: ' . $e->getResponse()->getStatusCode() . ' - ' .
                                      $e->getResponse()->getReasonPhrase(), 'error' => true];
            } else {
                return ['output' => 'Request error: ' . $e->getMessage(), 'error' => true];
            }
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        return ['output' => 'Unknown error', 'error' => true];
    }

    /**
     * parseDuration parses the duration string from the frontend
     * into something we can use with the Influx API.
     *
     * @param string $duration ISO8601 Duration
     * @param string $now current time (used in testing)
     * @return string
     */
    public static function parseDuration(\DateTime $now, string $duration): string
    {
        try {
            $int = new DateInterval($duration);
        } catch (Exception $e) {
            Logger::error('Failed to parse date interval: %s', $e);
            $int = new DateInterval('PT12H');
        }

        $ts = $now->sub($int);

        return $ts->getTimestamp();
    }

    public function getMetricCount(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
    ): array {

        $q = $this->generateBaseQuery($hostName, $serviceName, $checkCommand, $from, $isHostCheck);
        // We just need the count
        $q .= '|> count()';

        $query = $this->generateBaseRequest($q);

        $url = $this->URL . $this::QUERY_ENDPOINT;

        Logger::debug('Calling query API at %s with count query: %s', $url, $query);

        $response = $this->client->request('POST', $url, $query);
        $stream = new FluxCsvParser($response->getBody(), true);

        $metricStats = [];
        foreach ($stream->each() as $record) {
            $metricname = $record['metric'];
            $metricStats[$metricname] = $record->getValue();
        }

        return $metricStats;
    }

    /**
     * getAggregateWindow calculates the size of the aggregate window.
     * If there is no need to aggregate it returns 0.
     *
     * @param string $from timestamp in seconds
     * @param array $count count of datapoints
     * @return int size of the aggregation window in seconds
     */
    protected function getAggregateWindow(string $from, array $count): int
    {
        // Since all time series are part of the same check, they have the same count
        $numOfDatapoints = array_pop($count);

        // If there are less datapoints than the max, we can just return
        if ($numOfDatapoints < $this->maxDataPoints) {
            return 0;
        }

        $now = (new DateTime())->getTimestamp();
        $from = intval($from);
        // If there are datapoints than allowed we calculate an aggregation window size
        if ($numOfDatapoints > $this->maxDataPoints) {
            return (int) round(($now - $from) / $this->maxDataPoints);
        }

        return 0;
    }

    /**
     * fromConfig returns a new Influx Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(Config $moduleConfig = null): Influx
    {
        $default = [
            'api_url' => 'http://localhost:8086',
            'api_timeout' => 10,
            'api_bucket' => '',
            'api_org' => '',
            'api_token' => '',
            'api_max_data_points' => 10000,
            'api_tls_insecure' => false,
            'writer_host_name_template_tag' => 'hostname',
            'writer_service_name_template_tag' => 'service',
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs InfluxDBv2 module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphsinfluxdbv2');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs InfluxDBv2 module configuration: %s', $e);
                return $default;
            }
        }

        $baseURI = rtrim($moduleConfig->get('influx', 'api_url', $default['api_url']), '/');
        $timeout = (int) $moduleConfig->get('influx', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('influx', 'api_max_data_points', $default['api_max_data_points']);
        $org = $moduleConfig->get('influx', 'api_org', $default['api_org']);
        $bucket = $moduleConfig->get('influx', 'api_bucket', $default['api_bucket']);
        $token = $moduleConfig->get('influx', 'api_token', $default['api_token']);
        $hostnameTag = $moduleConfig->get('influx', 'writer_host_name_template_tag', $default['writer_host_name_template_tag']);
        $servicenameTag = $moduleConfig->get('influx', 'writer_service_name_template_tag', $default['writer_service_name_template_tag']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('influx', 'api_tls_insecure', $default['api_tls_insecure']);

        return new static(
            baseURI: $baseURI,
            org: $org,
            bucket: $bucket,
            token: $token,
            hostnameTag: $hostnameTag,
            servicenameTag: $servicenameTag,
            timeout: $timeout,
            maxDataPoints: $maxDataPoints,
            tlsVerify: $tlsVerify
        );
    }
}
