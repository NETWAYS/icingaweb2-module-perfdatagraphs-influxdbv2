<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Client\Transformer;

use GuzzleHttp\Psr7\Response;

class TransfomerBench
{
    protected function loadTestdata(string $file)
    {
        $data = [];

        $response = new Response(404);

        if (file_exists($file)) {
            $jsonContent = file_get_contents($file);
            $response = new Response(
                200,
                ['Content-Type' => 'application/csv'],
                $jsonContent
            );
        }
        return $response;
    }

    /**
     * @Revs(100)
     * @Iterations(10)
     */
    public function benchTransform()
    {
        $input = $this->loadTestdata(__DIR__ .'/testdata/load.csv');
        $actual = Transformer::transform($input);
    }
}
