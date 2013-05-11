<?php
namespace Autometrics;

interface Metric {
	public function __construct( $name );
	public function set_type( $type);
	public function set_timestamp( $timestamp );
	public function set_value( $value );
	public function add_tag( $key, $value);
	public function add_tags_array( array $metadata);
	public function add_metadata(  $key,  $value);
	public function add_metadata_array( array $metadata);
	public function get_value();
	public function get_timestamp();
	public function get_type();
	public function get_tags();
	public function get_metadata();
	public function __toString();
}

interface SimpleMetric {
	public function __construct( $name );
	public function set_type( $type);
	public function set_timestamp( $timestamp );
	public function set_value( $value );
	public function get_value();
	public function get_timestamp();
	public function get_type();
	public function __toString();
}

interface Set
{
	public function __construct( $name, $type );
	public function add_metric( SimpleMetric $metric);
	public function add_value($value);
	public function reset();
	public function add_tag( $key, $value);
	public function add_tags_array( array $metadata);
	public function add_metadata(  $key,  $value);
	public function add_metadata_array( array $metadata);
	public function get_timeseries();
	public function get_type();
	public function get_name();
	public function get_metrics();
}

trait NumericValues
{
	public function increment()
	{
		$this->value += 1;
	}

	public function decrement()
	{
		$this->value -= 1;
	}

	public function reset()
	{
		$this->value = 0;
	}
}

trait DataSetVars
{
	private $name = '';
	private $type = '';
	private $metrics = array();
	private $points = array();
	private $values = array();
}

trait DataSetFunctions
{
	use DataSetVars;

	public function __construct($name, $type)
	{
		$this->name = $name;
		$this->type = $type;
		$this->metrics = array();
		$this->values = array();
		$this->points = array();
	}

	public function reset()
	{
		$this->values = array();
		$this->points = array();
		$this->metrics = array();
	}

	public function add_value($value, $timestamp = 0)
	{
		if ($timestamp == 0)
		{
			$timestamp = microtime(true);
		}

		$metric = MetricFactory::build_simple_metric($this->type, $this->name, $timestamp, $value);
		$this->add_metric($metric);
	}

	public function add_metric(SimpleMetric $metric)
	{
		$this->metrics[] = $metric;

		if (!array_key_exists($metric->get_timestamp(), $this->points) || ! is_array($this->points[$metric->get_timestamp()]))
		{
			$this->points[$metric->get_timestamp()] = array();
		}

		$this->points[$metric->get_timestamp()][] = $metric->get_value();
		$this->values = $metric->get_value();
	}

	public function get_metrics()
	{
		return $this->metrics;
	}

	public function get_name()
	{
		return $this->name;
	}

	public function get_type()
	{
		return $this->type;
	}

	public function get_timeseries()
	{
		$ret = array();
		$agg = $this->select_default_aggregator();
		foreach ($this->points as $timestamp => $values)
		{
			if (method_exists($this, $agg))
			{
				$ret[$timestamp] = $this->$agg($values);
			}
		}
		return $ret;
	}

	private function select_default_aggregator()
	{
		$agg = 'sum';
		switch ($this->type)
		{
			case 'counter':
			case 'meter':
				$agg = 'sum';
				break;
			case 'timer':
				$agg = 'avg';
				break;
		}
		return $agg;
	}

	public function to_array()
	{
		$ret = array();
		$ret['name'] = $this->get_name();
		$ret['type'] = $this->get_type();
		if (method_exists($this, 'extend_set_array'))
		{
			$this->extend_set_array($ret);
		}

		if (method_exists($this, 'extend_stats_array'))
		{
			$this->extend_stats_array($ret);
		}
		return $ret;
	}

	public function __toString()
	{
		return json_encode($this->to_array(), JSON_NUMERIC_CHECK);
	}
}

trait HistogramFunctions
{
	use AnalyticsFunctions;
	use DataSetVars;
	/**
	 *
	 * Extend Stats class to include Histogram support
	 * borrows heavily from Jesus M Castagnetto's code published here: http://px.sklar.com/code.html?id=119
	 * @author jcreasy
	 */
	/*
	 * // Original Header
	* This is a histogram class that accepts and unidimensional array of data
	* Returns 2 arrays by using the getStats() and getBins() methods.
	* Note: Tested only w/ PHP 3.0.7
	* (c) Jesus M. Castagnetto, 1999.
	* Gnu GPL'd code, see www.fsf.org for the details.
	*/

	private $bins = array();

/*	private function print_bins()
	{
		$s = sprintf("Number of bins: %s\n", count($this->bins));
		$s .= sprintf("BIN\tVAL\t\tFREQ\n");

		$maxbin = max($this->bins);
		reset($this->bins);

		for ($i = 0; $i < count($this->bins); $i++) {
			list($key, $val) = each($this->bins);
			$s .= sprintf("%d\t%-8.2f\t%-8d |%s\n", $i + 1, $key, $val, $this->print_bar($val, $maxbin));
		}
		return $s;
	}
*/
	private function number_of_bins()
	{
		$count = count(array_unique($this->values));

		//http://www.amazon.com/Jurans-Quality-Control-Handbook-Juran/dp/0070331766
		if ($count < 1) {
			return 0;
		}

		if ($count < 20) {
			return 5;
		}

		if ($count <= 50) {
			return 6;
		}

		if ($count <= 100) {
			return 7;
		}

		if ($count <= 200) {
			return 8;
		}

		if ($count <= 500) {
			return 9;
		}

		if ($count <= 1000) {
			return 10;
		}

		if ($count <= 5000) {
			$n = ($count / 100) + 1;
		}

		return 52;
	}

	private function validate()
	{
		if ($this->count() <= 1) {
			throw new Exception("Not enough data, " . $this->count() . " values");
		}

		if ($this->number_of_bins() < 1) {
			throw new Exception("Insufficient number of bins.");
		}

		return;
	}

/*	private function print_bar($val, $maxbin)
	{
		$fact = (float)($maxbin > 40) ? 40 / $maxbin : 1;
		$niter = (int)$val * $fact;
		$out = "";

		for ($i = 0; $i < $niter; $i++) {
			$out .= "*";
		}

		return $out;
	}
*/
	public function histogram($number_of_bins = NULL, $first_bin = NULL, $bin_width = NULL)
	{
		$bin = array();

		/* init bins array */
		if (empty($number_of_bins)) {
			$number_of_bins = $this->number_of_bins();
		}

		/* width of bins */
		if (empty($bin_width)) {
			$bin_width = $this->delta($number_of_bins);
		}

		if (empty($first_bin)) {
			$first_bin = $this->min();
		}

		for ($i = 0; $i < $number_of_bins; $i++) {
			$bin[$i] = (float)$first_bin + $bin_width * $i;
			$this->bins[(string)$bin[$i]] = 0;
		}

		/* calculate frequencies and populate bins array */
		$data = $this->values;
		$tmp = ($number_of_bins - 1);

		for ($i = 0; $i < $this->count(); $i++) {
			for ($j = $tmp; $j >= 0; $j--) {
				if ($data[$i] >= $bin[$j]) {
					$this->bins[(string)$bin[$j]]++;
					break;
				}
			}
		}
	}

	public function delta($number_of_bins = NULL)
	{
		if (empty($number_of_bins)) {
			$number_of_bins = $this->number_of_bins();
		}
		return (float)($this->max() - $this->min()) / $number_of_bins;
	}

	/* send back BINS array */
	public function get_bins()
	{
		return $this->bins;
	}

	public function __toString()
	{
		$s = sprintf("%s\n%s\n", $this->print_stats(), $this->print_bins());
		return $s;
	}

	public function extend_stats_array( array &$ret)
	{
		$ret['histogram'] = $this->get_bins();
		return $ret;
	}
}

trait AnalyticsFunctions
{
	use DataSetVars;

	public function range()
	{
		return $this->max() - $this->min();
	}

	public function timespan()
	{
		$span = $this->max_timestamp() - $this->min_timestamp();
		return $span;
	}

	public function rate($unit_length = 1)
	{
		return $this->count() / ( $this->timespan() / $unit_length);
	}

	public function count($data = NULL)
	{
		if (empty($data))
		{
			$data = $this->values;
		}

		return count($data);
	}

	public function median()
	{
		$median = $this->percentile(50);
		return $median;
	}

	public function min_timestamp($points = NULL)
	{
		if (empty($points))
		{
			$points = $this->points;
		}

		return min (array_keys($points));
	}

	public function max_timestamp()
	{
		return max(array_keys($this->points));
	}

	public function sum($data = NULL)
	{
		$sum = 0;

		if (empty($data))
		{
			$data = $this->values;
		}

		foreach ($data as $value) {
			$sum += $value;
		}
		return $sum;
	}

	public function sum2($data = NULL)
	{
		$sum = 0;

		if (empty($data))
		{
			$data = $this->values;
		}

		foreach ($data as $value) {
			$sum += (float)pow($value, 2);
		}

		return $sum;
	}

	public function avg($data = NULL)
	{
		if (empty($data))
		{
			$data = $this->values;
		}

		if ($this->count($data) == 0 || $this->sum($data) == 0) {
			return 0;
		}

		return $this->sum($data) / $this->count($data);
	}

	public function min($data = NULL)
	{
		if (empty($data))
		{
			$data = $this->values;
		}

		if (count($this->values) < 1) {
			return 0;
		}

		return (float)min($data);
	}

	public function max($data = NULL)
	{
		if (empty($data))
		{
			$data = $this->values;
		}

		if (count($data) < 1) {
			return 0;
		}

		return (float)max($data);
	}

	public function standard_deviation($data = NULL)
	{
		if (empty($data))
		{
			$data = $this->values;
		}
		return sqrt(($this->sum2($data) - $this->count($data) * pow($this->avg($data), 2)) / (float)($this->count($data) - 1));
	}
	/*
		public function holt_winters($aggregator = 'sum', $season_length = 7, $alpha = 0.2, $beta = 0.01, $gamma = 0.01, $dev_gamma = 0.1)
		{
			$ret = PhpIR::holt_winters($this->get_values($aggregator), 10, 0.1, 0.01, 0.01, 0.1);
			return $ret;
		}

		public function __toString()
		{
			return $this->to_string();
		}

		public function to_string()
		{
			$s = '';
			$s .= sprintf("N = %8d\tRange = %-8.0f\tMin = %-8.4f\tMax = %-8.4f\tAvg = %-8.4f\n", $this->count(), $this->range(), $this->min(), $this->max(), $this->avg());
			$s .= sprintf("StDev = %-8.4f\tSum = %-8.4f\tSum^2 = %-8.4f\n", $this->standard_deviation(), $this->sum(), $this->sum2());
			return $s;
		}

		public function to_array($include_points = false)
		{
			$ret = array();
			$ret['metric'] = array('runmode' => $this->runmode, 'class' => $this->class, 'type' => $this->metric, 'interval' => $this->interval);

			$stats = array();
			$stats['min'] = $this->min();
			$stats['max'] = $this->max();
			$stats['count'] = $this->count();
			$stats['sum'] = $this->sum();
			$stats['sum2'] = $this->sum2();
			$stats['avg'] = $this->avg();
			$stats['stdv'] = $this->standard_deviation();
			$ret['stats'] = $stats;
			if ($include_points === true) {
				$ret['points'] = $this->points;
				$ret['holt_winters'] = $this->holt_winters();
			}
			return $ret;
		}

		public function to_json($include_points = false)
		{
			$ret = $this->to_array($include_points);
			$ret = json_encode($ret);
			return $ret;
		}
	*/
	public function percentile($percentile)
	{
		if (empty($percentile) || !is_numeric($percentile)) {
			throw new Analytics_Exception('invalid percentile ' . $percentile);
		}

		if (0 < $percentile && $percentile < 1) {
			$p = $percentile;
		} else if (1 < $percentile && $percentile <= 100) {
			$p = $percentile * .01;
		} else {
			throw new Analytics_Exception('invalid percentile ' . $percentile);
		}

		if (empty($this->values) || !is_array($this->values)) {
			throw new Analytics_Exception('invalid data');
		}

		$count = count($this->values);

		$allindex = ($count - 1) * $p;

		$intvalindex = intval($allindex);

		$floatval = $allindex - $intvalindex;

		sort($this->values);

		if (!is_float($floatval)) {
			$result = $this->values[$intvalindex];
		} else {
			if ($count > $intvalindex + 1) {
				$result = $floatval * ($this->values[$intvalindex + 1] - $this->values[$intvalindex]) + $this->values[$intvalindex];
			} else {
				$result = $this->values[$intvalindex];
			}
		}
		return $result;
	}

	public function quartiles()
	{
		$q1 = $this->percentile(25);
		$q2 = $this->percentile(50);
		$q3 = $this->percentile(75);
		$quartile = array('25' => $q1, '50' => $q2, '75' => $q3);
		return $quartile;
	}

	public function extend_set_array(array &$ret, $include_points = false)
	{
		$stats = array();
		$stats['min'] = $this->min();
		$stats['max'] = $this->max();
		$stats['count'] = $this->count();
		$stats['sum'] = $this->sum();
		$stats['sum2'] = $this->sum2();
		$stats['avg'] = $this->avg();
		$stats['stdv'] = $this->standard_deviation();
		$ret['stats'] = $stats;

		if ($include_points === true) {
			$ret['points'] = $this->points;
			//$ret['holt_winters'] = $this->holt_winters();
		}

		return $ret;
	}
}

trait MetaDataFunctions
{
	private $tags = array();
	private $metadata = array();

	public function add_tag($key, $value)
	{
		$this->tags[$key] = $value;
	}

	public function add_tags_array($params)
	{
		if (!is_array($params))
		{
			throw new Autometrics_Exception(__METHOD__ . ": supplied params is not an array");
		}

		foreach ($params as $k => $v)
		{
			$this->tags[$k] = $v;
		}
	}

	public function add_metadata($key, $value)
	{
		$this->metadata[$key] = $value;
	}

	public function add_metadata_array($params)
	{
		if (!is_array($params))
		{
			throw new Autometrics_Exception(__METHOD__ . ": supplied params is not an array");
		}

		foreach ($params as $k => $v)
		{
			$this->metadata[$k] = $v;
		}
	}

	public function get_tags()
	{
		return $this->tags();
	}

	public function get_metadata()
	{
		return $this->metadata();
	}

	private function extend_simple_array( array &$ret)
	{
		$ret['tags'] = $this->get_tags();
		$ret['metadata'] = $this->get_metadata();
	}
}

trait MetricFunctions
{
	private $type = 'counter';
	private $value = '1';
	private $timestamp = NULL;
	private $iso8601 = NULL;


	public function set_type(  $type)
	{
		if (in_array($type, array('timer', 'counter', 'meter', 'gauge')))
		{
			$this->type = $type;
		}
		else
		{
			throw new Autometrics_Exception(__METHOD__ . ": $type is not a valid metric type");
		}
	}

	public function set_timestamp( $timestamp)
	{
		$datetime =  date_create($timestamp);
		$this->timestamp = DateTime::Format($datetime, 'U');
		$this->iso8601 = DateTime::Format($datetime, 'c');
	}

	public function set_value( $value )
	{
		if (is_int($value) || is_float($value))
		{
			$this->value = $value;
		}
	}

	public function get_name()
	{
		return $this->name();
	}

	public function get_value()
	{
		return $this->value();
	}

	public function get_timestamp()
	{
		return $this->timestamp();
	}

	public function get_type()
	{
		return $this->type();
	}

	private function simple_array()
	{
		$ret = array();
		$ret['name'] = $this->get_name();
		$ret['type'] = $this->get_type();
		$ret['value'] = $this->get_value();
		$ret['timestamp'] = $this->get_timestamp();
	}

	public function __toString()
	{
		$ret = $this->simple_array();

		if (method_exists($this, 'extend_simple_array'))
		{
			$this->extend_simple_array($ret);
		}

		return json_encode($ret, JSON_NUMERIC_CHECK);
	}
}

trait RequiredMetadata
{
	private function add_base_metadata(&$metadata)
	{
		\Autometrics\Helper::add_required_metadata($metadata);
	}
}

trait ExtendedMetadata
{
	private function add_base_metadata(&$metadata)
	{
		\Autometrics\Helper::add_full_metadata($metadata);
	}
}

trait RequiredTags
{
	private function add_base_tags(&$tags)
	{
		\Autometrics\Helper::add_required_tags($tags);
	}
}

trait ExtendedTags
{
	private function add_base_tags(&$tags)
	{
		\Autometrics\Helper::add_full_tags($tags);
	}
}

class DataSet implements Set
{
	use DataSetFunctions;
	use MetaDataFunctions;
	use AnalyticsFunctions;

	public function get_values($aggregator = 'sum')
	{
		ksort($this->points);

		switch (strtolower($aggregator)) {
			case 'sum':
				foreach ($this->points as $timestamp => $values) {
					$a["$timestamp"] = $this->sum($values);
				}
				break;
			case 'avg':
				foreach ($this->points as $timestamp => $values) {
					$a["$timestamp"] = $this->avg($values);
				}
				break;
			case 'max':
				foreach ($this->points as $timestamp => $values) {
					$a["$timestamp"] = $this->max($values);
				}
				break;
			case 'min':
				foreach ($this->points as $timestamp => $values) {
					$a["$timestamp"] = $this->min($values);
				}
				break;
			default:
				foreach ($this->points as $timestamp => $values) {
					$a["$timestamp"] = $this->sum($values);
				}
				break;
		}
		return $a;
	}
}


class MetricFactory
{
	public static function build_simple_metric($type, $name, $timestamp, $value)
	{
		switch (strtolower($type))
		{
			case 'counter':
				$metric = new SimpleCounter($name);
				break;
			case 'timer':
				$metric = new SimpleTimer($name);
				break;
			case 'meter':
				$metric = new SimpleMeter($name);
				break;
			case 'gauge':
				$metric = new SimpleGauge($name);
				break;
			default:
				throw new Autometrics_Exception('Invalid SimpleMetric Type ' . $type);
		}

		$metric->set_value($value);
		$metric->set_timestamp($timestamp);
		return $metric;
	}
}


class Counter implements Metric
{
	use MetricFunctions;
	use MetaDataFunctions;
	use NumericValues;
	use ExtendedMetadata;
	use ExtendedTags;

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'counter';
		$this->timestamp = microtime(true);
		$this->value = 1;

		$this->metadata = array();
		$this->add_base_metadata($this->metadata);

		$this->tags = array();
		$this->add_base_tags($this->tags);
	}
}

class Timer implements Metric
{
	use MetricFunctions;
	use MetaDataFunctions;
	use ExtendedMetadata;
	use ExtendedTags;
	private $units = 'ms';

	public function set_units($units = 'ms')
	{
		$this->units = $units;
	}

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'timer';

		$this->timestamp = microtime(true);

		$this->metadata = array();
		$this->add_base_metadata($this->metadata);

		$this->tags = array();
		$this->add_base_tags($this->tags);
	}
}

class Meter implements Metric
{
	use MetricFunctions;
	use MetaDataFunctions;
	use ExtendedMetadata;
	use ExtendedTags;
	use NumericValues;

	private $units;
	private $event_type;

	public function set_units($units = 'SECOND')
	{
		$this->units = $units;
	}

	public function set_event_type($type = 'requests')
	{
		$this->event_type = $type;
	}

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'meter';
		$this->value = 1;
		$this->timestamp = microtime(true);
		$this->set_units();
		$this->set_event_type();
		$this->metadata = array();
		$this->add_base_metadata($this->metadata);

		$this->tags = array();
		$this->add_base_tags($this->tags);
	}
}

class Gauge implements Metric
{
	use MetricFunctions;
	use MetaDataFunctions;
	use ExtendedMetadata;
	use ExtendedTags;
	use NumericValues;

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'counter';
		$this->timestamp = microtime(true);
		$this->value = 1;

		$this->metadata = array();
		$this->add_base_metadata($this->metadata);

		$this->tags = array();
		$this->add_base_tags($this->tags);
	}
}

class SimpleCounter implements SimpleMetric
{
	use MetricFunctions;
	use NumericValues;

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'counter';
		$this->timestamp = microtime(true);
		$this->value = 1;
	}
}

class SimpleTimer implements SimpleMetric
{
	use MetricFunctions;
	private $units = 'ms';

	public function set_units($units = 'ms')
	{
		$this->units = $units;
	}

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'timer';

		$this->timestamp = microtime(true);
	}
}

class SimpleMeter implements SimpleMetric
{
	use MetricFunctions;
	use NumericValues;

	private $units;
	private $event_type;

	public function set_units($units = 'SECOND')
	{
		$this->units = $units;
	}

	public function set_event_type($type = 'requests')
	{
		$this->event_type = $type;
	}

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'meter';
		$this->value = 1;
		$this->timestamp = microtime(true);
		$this->set_units();
		$this->set_event_type();
	}
}

class SimpleGauge implements SimpleMetric
{
	use MetricFunctions;
	use NumericValues;

	public function __construct( $name )
	{
		$this->name = $name;
		$this->type = 'counter';
		$this->timestamp = microtime(true);
		$this->value = 1;
	}
}
