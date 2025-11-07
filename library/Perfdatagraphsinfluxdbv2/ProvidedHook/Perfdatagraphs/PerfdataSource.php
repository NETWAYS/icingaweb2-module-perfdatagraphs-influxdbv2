<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\ProvidedHook\PerfdataGraphs;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Client\Influx;
use Icinga\Module\Perfdatagraphsinfluxdbv2\Client\Transformer;

use Icinga\Module\Perfdatagraphs\Hook\PerfdataSourceHook;
use Icinga\Module\Perfdatagraphs\Model\PerfdataRequest;
use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;

use Icinga\Application\Benchmark;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateTime;
use Exception;

class PerfdataSource extends PerfdataSourceHook
{
    public function getName(): string
    {
        return 'InfluxDBv2';
    }

    public function fetchData(PerfdataRequest $req): PerfdataResponse
    {
        // Parse the duration
        $now = new DateTime();
        $from = Influx::parseDuration($now, $req->getDuration());

        $perfdataresponse = new PerfdataResponse();

        Benchmark::measure('Fetching performance data from InfluxDB v2');

        // Create a client and get the data from the API
        try {
            $client = Influx::fromConfig();
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
            return $perfdataresponse;
        }

        try {
            $response = $client->getMetrics(
                $req->getHostname(),
                $req->getServicename(),
                $req->getCheckcommand(),
                $from,
                $req->isHostCheck()
            );
        } catch (ConnectException $e) {
            $perfdataresponse->addError($e->getMessage());
        } catch (RequestException $e) {
            $perfdataresponse->addError($e->getMessage());
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
        }

        Benchmark::measure('Fetched performance data from InfluxDB v2');

        // Why even bother when we have errors here
        if ($perfdataresponse->hasErrors()) {
            return $perfdataresponse;
        }

        Benchmark::measure('Transforming performance data from InfluxDB v2');

        try {
            // Transform into the PerfdataSourceHook format
            $perfdataresponse = Transformer::transform($response, $req->getIncludeMetrics(), $req->getExcludeMetrics());
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
        }

        Benchmark::measure('Transformed performance data from InfluxDB v2');

        return $perfdataresponse;
    }
}
