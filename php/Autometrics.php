<?php
/**
 * This class is to assist with formatting events and sending them as metrics to Autometrics
 *
 */
class Helper_Autometrics
{
	const OPTIMISTIC_CREATION	= true;
	const SERVER_TYPE_REGEX		= '/[\-A-Za-z]+/';
	const DEFAULT_UNKNOWN		= 'unspecified';

	const METRIC_NAME		= 'metric_name';
	const GLOBAL_METRIC_NAME	= 'event_type';
	const METRIC_VALUE		= 'metric_value';
	const METRIC_TYPE		= 'metric_type';
	const METRIC_TIMESTAMP		= 'created';
	const METRIC_TAGS		= 'metric_tags';
	const METRIC_METADATA		= '@metadata';

	/**
	 * @param array $params
	 */
	private static function add_required_metadata(&$metadata)
	{
		Helper_Analytics::add_if_not_exists_and_not_empty($metadata, 'request_id', $GLOBALS['request_id']);
		Helper_Analytics::add_if_not_exists_and_not_empty($metadata, 'ip_addr', Helper_Client::get_ip());
		Helper_Analytics::add_if_not_exists_and_not_empty($metadata, 'country_code', Helper_Client::get_country_code());

		Helper_Analytics::add_required_metadata($metadata);
		Helper_Analytics::add_extended_metadata($metadata);
	}

	/**
	 * @param array $tags
	 */
	private static function add_required_tags(&$tags)
	{
		self::get_host_tags_from_hostname($tags);

		Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'env', Helper_Environment::get_application_source());
		Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'api_key', Helper_Client::get_api_key());
		Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'version', Helper_Environment::get_version());
	}

	/**
	 * @param $tags
	 */
	private static function get_host_tags_from_hostname(&$tags)
	{
		$hostname = gethostname();
		$matches = array();

		$parts = explode('.', $hostname);

		if (!array_key_exists('domain', $tags))
		{
			$domain = $parts[1];

			for ($i = 2; $i < length($parts); $i++)
			{
				$domain .= '.' . $parts[i];
			}

			Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'domain', $domain);
		}

		Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'host', $parts[0]);

		if (!array_key_exists('server_type', $tags))
		{
			if (preg_match(self::SERVER_TYPE_REGEX, $hostname, $matches))
			{
				Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'server_type', $matches[0]);
			} else {
				Helper_Analytics::add_if_not_exists_and_not_empty($tags, 'server_type', self::DEFAULT_UNKNOWN);
			}				
		}
	}

	/**
	 * We want to be flexible about what we can turn into a metric, so, if all else fails as long
	 * as we have a name we'll try to at least make it a counter with a value of +1.
	 *
	 * @param $params
	 */
	private static function initialize_metric(&$params)
	{
		// Initialize @metadata
		if (!array_key_exists(self::METRIC_METADATA, $params))
		{
			$params[self::METRIC_METADATA] = array();
		}

		// Initialize tags
		if (!array_key_exists(self::METRIC_TAGS, $params))
		{
			$params[self::METRIC_TAGS] = array();
		}

		if (array_key_exists(self::GLOBAL_METRIC_NAME, $params))
		{
			$params[self::METRIC_TAGS][self::GLOBAL_METRIC_NAME] = $params[self::GLOBAL_METRIC_NAME];
			$params[self::METRIC_METADATA][self::GLOBAL_METRIC_NAME] = $params[self::GLOBAL_METRIC_NAME];
			unset($params[self::GLOBAL_METRIC_NAME]);

			if (!array_key_exists(self::METRIC_NAME, $params))
			{
				$params[self::METRIC_NAME] = 'analytics.event';
			}
		}

		if (self::OPTIMISTIC_CREATION)
		{
			if (!array_key_exists(self::METRIC_TYPE, $params) || empty($params[self::METRIC_TYPE]))
			{
				$params[self::METRIC_TYPE] = 'counter';
			}

			if (!array_key_exists(self::METRIC_VALUE, $params) || empty($params[self::METRIC_VALUE]))
			{
				$params[self::METRIC_VALUE] = '1';
			}

			$params[self::METRIC_TIMESTAMP] = $params[self::METRIC_TIMESTAMP] ? : microtime(true);
		}
	}

	/**
	 * @param array $params
	 */
	private static function move_non_standard_keys_to_metadata(&$params)
	{
		foreach ($params as $key => $value)
		{
			switch ($key)
			{
				case self::METRIC_NAME:
				case self::METRIC_TYPE:
				case self::METRIC_VALUE:
				case self::METRIC_TIMESTAMP:
				case self::METRIC_METADATA:
				case self::METRIC_TAGS:
					break;
				default:
					$params[self::METRIC_METADATA][$key] = $value;
					unset($params[$key]);
					break;
			}
		}
	}

	/**
	 * @param $params
	 * @return bool
	 */
	private static function is_valid_metric($params)
	{
		if (!is_array($params))
		{
			return false;
		}

		$required_fields = array(
				self::METRIC_NAME,
				self::METRIC_TYPE,
				self::METRIC_TIMESTAMP,
				self::METRIC_VALUE,
		);
	
		foreach ($required_fields as $field)
		{
			if ( ! array_key_exists($field, $params)
			{
				Helper_Environment::throw_if_not_live(new Analytics_Exception(__METHOD__ . ": $field not provided and no default value"));
				return false;
			}
		}

		if ($params[self::METRIC_TYPE] != 'timer' && $params[self::METRIC_TYPE] != 'counter'))
		{
			Helper_Environment::throw_if_not_live(new Analytics_Exception(__METHOD__ . ": Only timer and counter objects are supported: " . $params[self::METRIC_TYPE]));
			return false;
		}

		if ( ! is_numeric($params[self::METRIC_VALUE]))
		{
			Helper_Environment::throw_if_not_live(new Analytics_Exception(__METHOD__ . ": value must be numeric: " . $params[self::METRIC_VALUE]));
			return false;
		}

		if ( ! strtotime($params[self::METRIC_TIMESTAMP]))
		{
			Helper_Environment::throw_if_not_live(new Analytics_Exception(__METHOD__ . ": TIMESTAMP could not be parsed: " . $params[self::METRIC_TIMESTAMP]));
			return false;
		}

		return true;
	}

	/**
	 * Create a metric from individual values
	 * minimum requirement is name, defaults to +1 counter
	 *
	 * @param string $name
	 * @param float|int $value
	 * @param string $type
	 * @param array $tags
	 * @param array $params
	 * @param array $metadata
	 * @return array
	 */
	public static function produce_metric_from_values($name, $value = 1, $type = 'counter', $tags = array(), $params = array(), $metadata = array())
	{
		$metric = array();
		$metric[self::METRIC_TYPE] = $type;
		$metric[self::METRIC_NAME] = $name;
		$metric[self::METRIC_TAGS] = $tags;
		$metric[self::METRIC_VALUE] = $value;
		$metric[self::METRIC_METADATA] = $metadata;

		if (!is_array($params))
		{
			$params = array(self::GLOBAL_METRIC_NAME => $params);
		}

		foreach ($params as $key => $value)
		{
			$metric[$key] = $value;
		}

		$metric = self::produce_metric_from_params($metric, $send_metric);
		return $metric;
	}

	/**
	 * Supports Timers and Counters only, not meters, gauges or checkpoint events.
	 * Requires: ('name' or 'event_type') for counter, ('name' or 'event_type') and 'value' for timer
	 *
	 * @param array $params
	 * @param bool $send_metric
	 * @return array
	 */
	public static function produce_metric_from_params($params, $send_metric = true)
	{
		self::initialize_metric($params);

		if ( ! self::is_valid_metric($params))
		{
			Helper_Environment::throw_if_not_live(new Analytics_Exception(__METHOD__ . ": unable to create a metric from provided params");
			return false;
		}

		self::move_non_standard_keys_to_metadata($params);
		self::add_required_metadata($params[self::METRIC_METADATA]);
		self::add_required_tags($params[self::METRIC_TAGS]);

		// For consistent serialization we want the keys to be consistently ordered.
		// This helps with Unit_Tests and makes it easer to parse read streams of metric events
		ksort($params);

		return $params;
	}
}
