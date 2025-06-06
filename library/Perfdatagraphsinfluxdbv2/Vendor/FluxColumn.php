<?php
# The MIT License
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Vendor;

/**
 * Class FluxColumn represents a column header specification of FluxTable.
 * @package InfluxDB2
 */
class FluxColumn
{
    public $index;
    public $label;
    public $dataType;
    public $group;
    public $defaultValue;

    /**
     * FluxColumn constructor.
     * @param $index int column number
     * @param $label string column label
     * @param $dataType string data type
     * @param $group bool is group column
     * @param $defaultValue string default empty value
     */
    public function __construct($index = null, $label = null, $dataType = null, $group = null, $defaultValue = null)
    {
        $this->index = $index;
        $this->label = $label;
        $this->dataType = $dataType;
        $this->group = $group;
        $this->defaultValue = $defaultValue;
    }
}
