<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

use Icinga\Module\Perfdatagraphs\Icingadb\IcingaObjectHelper as IcinaDBCVH;
use Icinga\Module\Perfdatagraphs\Ido\IcingaObjectHelper as IdoCVH;
use Icinga\Module\Perfdatagraphs\ProvidedHook\Icingadb\IcingadbSupport;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Application\Modules\Module;

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
    protected int $maxDataPoints;

    public function __construct(
        string $baseURI,
        string $org,
        string $bucket,
        string $token,
        int $timeout = 2,
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
    }

    public function getMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
    ): Response {

        $q = sprintf('baseData = () => from(bucket: "%s")
               |> range(start: %s)
               |> filter(fn: (r) => r._measurement == "%s")
               |> filter(fn: (r) => r["hostname"] == "%s")
               |> filter(fn: (r) => r["service"] == "%s")', $this->bucket, $from, $checkCommand, $hostName, $serviceName);

        // We need a different aggregateWindow function for different fields
        $q .= 'getSet = (tables=<-, field, fn) => tables
        |> filter(fn: (r) => r._field == field)';

        var_dump($this->maxDataPoints);
        if ($this->maxDataPoints > 0) {
            $windowEverySeconds = $this->getAggregateWindow($hostName, $serviceName, $checkCommand, $isHostCheck, $from);

            var_dump($windowEverySeconds);
            if ($windowEverySeconds > 0) {
                $q .= sprintf('|> aggregateWindow(fn: fn, every: %ss)', $windowEverySeconds);
            }
        }

        // unit is a string, we cannot use mean
        $q .= sprintf('
        value = baseData() |> getSet(field: "value", fn: mean)
        warn = baseData() |> getSet(field: "warn", fn: mean)
        crit = baseData() |> getSet(field: "crit", fn: mean)
        unit = baseData() |> getSet(field: "unit", fn: last)
        union(tables: [value, warn, crit, unit])');

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

    protected function getAggregateWindow(string $hostName, string $serviceName, string $checkCommand, bool $isHostCheck, string $from): int
    {
        $checkIntervalInSeconds = 60;

        // We need to fetch the check interval from the database
        if (Module::exists('icingadb') && IcingadbSupport::useIcingaDbAsBackend()) {
            Logger::debug('Used IcingaDB as database backend');
            $cvh = new IcinaDBCVH();
            $object = $cvh->getObjectFromString($hostName, $serviceName, $isHostCheck);
            $checkIntervalInSeconds = $object->check_interval;
        } else {
            Logger::debug('Used IDO as database backend');
            $cvh = new IdoCVH();
            $object = $cvh->getObjectFromString($hostName, $serviceName, $isHostCheck);

            if ($isHostCheck) {
                $checkIntervalInSeconds = $object->host_check_interval;
            } else {
                $checkIntervalInSeconds = $object->service_check_interval;
            }
        }

        $now = (new DateTime())->getTimestamp();
        $from = intval($from);
        // Estimate of how many datapoints we have in the given time range given the check interval
        // TODO: This only works if there is actual data
        // Alternatively we could run a COUNT query()
        $numOfDatapoints = round(($now - $from) / $checkIntervalInSeconds);
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
        $maxDataPoints = (int) $moduleConfig->get('graphite', 'api_max_data_points', $default['api_max_data_points']);
        $org = $moduleConfig->get('influx', 'api_org', $default['api_org']);
        $bucket = $moduleConfig->get('influx', 'api_bucket', $default['api_bucket']);
        $token = $moduleConfig->get('influx', 'api_token', $default['api_token']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('influx', 'api_tls_insecure', $default['api_tls_insecure']);

        return new static($baseURI, $org, $bucket, $token, $timeout, $maxDataPoints, $tlsVerify);
    }
}
