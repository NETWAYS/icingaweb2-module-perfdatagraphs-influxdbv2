<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Tests\Client;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Client\Influx;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for the configurable "measurement source" feature (check_command / hostname / static)
 * and the accompanying host/service tag-rename fix in Influx::getMetrics().
 */
class InfluxTest extends TestCase
{
    private function makeClient(
        string $hostnameTag = 'hostname',
        string $servicenameTag = 'service',
        string $measurementSource = 'checkcommand',
        ?string $measurementStaticValue = ''
    ): Influx {
        return new Influx(
            baseURI: 'http://localhost:8086',
            org: 'testorg',
            bucket: 'testbucket',
            hostnameTag: $hostnameTag,
            servicenameTag: $servicenameTag,
            measurementSource: $measurementSource,
            measurementStaticValue: $measurementStaticValue,
        );
    }

    /**
     * generateBaseQuery() is protected; call it via reflection instead of widening
     * visibility just for testing.
     */
    private function callGenerateBaseQuery(Influx $client, string $host, string $service, string $checkCommand): string
    {
        $method = new ReflectionMethod(Influx::class, 'generateBaseQuery');
        $method->setAccessible(true);

        return $method->invoke($client, $host, $service, $checkCommand, 0, false);
    }

    public function testMeasurementSourceDefaultsToCheckCommand(): void
    {
        $client = $this->makeClient();

        $query = $this->callGenerateBaseQuery($client, 'myhost', 'myservice', 'check_nrpe');

        $this->assertStringContainsString('r._measurement == "check_nrpe"', $query);
    }

    public function testMeasurementSourceHostname(): void
    {
        $client = $this->makeClient(measurementSource: 'hostname');

        $query = $this->callGenerateBaseQuery($client, 'myhost', 'myservice', 'check_nrpe');

        $this->assertStringContainsString('r._measurement == "myhost"', $query);
        $this->assertStringNotContainsString('r._measurement == "check_nrpe"', $query);
    }

    public function testMeasurementSourceStatic(): void
    {
        $client = $this->makeClient(measurementSource: 'static', measurementStaticValue: 'icinga');

        $query = $this->callGenerateBaseQuery($client, 'myhost', 'myservice', 'check_nrpe');

        $this->assertStringContainsString('r._measurement == "icinga"', $query);
    }

    public function testMeasurementSourceStaticEscapesQuotes(): void
    {
        // addslashes() must still be applied to the resolved measurement value,
        // regardless of which source it came from.
        $client = $this->makeClient(measurementSource: 'static', measurementStaticValue: 'weird"value');

        $query = $this->callGenerateBaseQuery($client, 'myhost', 'myservice', 'check_nrpe');

        $this->assertStringContainsString('r._measurement == "weird\\"value"', $query);
    }

    /**
     * Regression test: the config form only renders the "static measurement value"
     * field when measurement source is "static", so getValue() returns null for it
     * otherwise. The constructor must accept that without a TypeError.
     */
    public function testConstructorAcceptsNullMeasurementStaticValue(): void
    {
        $client = $this->makeClient(measurementSource: 'hostname', measurementStaticValue: null);

        $this->assertInstanceOf(Influx::class, $client);

        $query = $this->callGenerateBaseQuery($client, 'myhost', 'myservice', 'check_nrpe');
        $this->assertStringContainsString('r._measurement == "myhost"', $query);
    }

    /**
     * getMetrics() performs a real HTTP request via an internally constructed Guzzle
     * client, so we swap it out for a mocked one via reflection to capture the
     * actual Flux query that is sent.
     */
    private function captureSentQuery(Influx $client): string
    {
        $mock = new MockHandler([
            // 1st request: the count() query from getMetricCount(), used to determine
            // the aggregation window. Needs a real "_value" column (raw Flux count()
            // output, not pivoted) plus a "metric" column.
            new Response(200, [], implode("\r\n", [
                '#datatype,string,long,string,long',
                '#group,false,false,true,false',
                '#default,_result,,,',
                ',result,table,metric,_value',
                ',,0,mymetric,1',
                '',
            ])),
            // 2nd request: the actual data query built in getMetrics() itself (the one
            // with pivot/rename/keep). Its response is returned raw and never parsed
            // by getMetrics(), so the body content doesn't matter here.
            new Response(200, [], ''),
        ]);

        $container = [];
        $history = Middleware::history($container);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $guzzleClient = new GuzzleClient(['handler' => $handlerStack]);

        $clientProperty = new ReflectionProperty(Influx::class, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($client, $guzzleClient);

        $client->getMetrics('myhost', 'myservice', 'check_nrpe', 0, false);

        $this->assertCount(2, $container, 'Expected a count() request followed by the main data request');

        // Index 1 = the second request, i.e. the main data query with rename()/keep().
        $body = (string) $container[1]['request']->getBody();
        $decoded = json_decode($body, true);

        return $decoded['query'];
		}

    public function testGetMetricsRenamesNonDefaultHostnameTagToHost(): void
    {
        $client = $this->makeClient(hostnameTag: 'hostname', servicenameTag: 'service');

        $query = $this->captureSentQuery($client);

        $this->assertStringContainsString('rename(columns: {"hostname": "host"})', $query);
        // servicenameTag is already "service", so no rename is needed for it.
        $this->assertStringNotContainsString('rename(columns: {"service": "service"})', $query);
    }

    public function testGetMetricsSkipsRenameWhenTagsAlreadyMatchDefaults(): void
    {
        $client = $this->makeClient(hostnameTag: 'host', servicenameTag: 'service');

        $query = $this->captureSentQuery($client);

        $this->assertStringNotContainsString('rename(columns:', $query);
    }
}
