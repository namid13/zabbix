<?php
/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class shares common properties, constants and methods for different controllers used for item tests.
 */
abstract class CControllerPopupItemTest extends CController {
	/**
	 * Types of preprocessing tests, depending on type of item.
	 */
	const ZBX_TEST_TYPE_ITEM = 0;
	const ZBX_TEST_TYPE_ITEM_PROTOTYPE = 1;
	const ZBX_TEST_TYPE_LLD = 2;

	/**
	 * Define a set of item types allowed to test and item properties needed to collect for each item type.
	 *
	 * @var array
	 */
	public static $testable_item_types = [ITEM_TYPE_ZABBIX, ITEM_TYPE_SIMPLE, ITEM_TYPE_SNMPV1, ITEM_TYPE_SNMPV2C,
		ITEM_TYPE_SNMPV3, ITEM_TYPE_INTERNAL, ITEM_TYPE_AGGREGATE, ITEM_TYPE_EXTERNAL, ITEM_TYPE_DB_MONITOR,
		ITEM_TYPE_HTTPAGENT, ITEM_TYPE_IPMI, ITEM_TYPE_SSH, ITEM_TYPE_TELNET, ITEM_TYPE_JMX, ITEM_TYPE_CALCULATED
	];

	/**
	 * Item value type used if user has not specified one.
	 */
	const ZBX_DEFAULT_VALUE_TYPE = ITEM_VALUE_TYPE_TEXT;

	/**
	 * Item types requiring interface.
	 *
	 * @var array
	 */
	protected $items_require_interface = [ITEM_TYPE_ZABBIX, ITEM_TYPE_SNMPV1, ITEM_TYPE_SNMPV2C, ITEM_TYPE_SNMPV3,
		ITEM_TYPE_IPMI, ITEM_TYPE_SSH, ITEM_TYPE_TELNET, ITEM_TYPE_SIMPLE
	];

	/**
	 * Item types with proxy support.
	 *
	 * @var array
	 */
	protected $items_support_proxy = [ITEM_TYPE_ZABBIX, ITEM_TYPE_SIMPLE, ITEM_TYPE_SNMPV1, ITEM_TYPE_SNMPV2C,
		ITEM_TYPE_SNMPV3, ITEM_TYPE_INTERNAL, ITEM_TYPE_EXTERNAL, ITEM_TYPE_DB_MONITOR, ITEM_TYPE_HTTPAGENT,
		ITEM_TYPE_IPMI, ITEM_TYPE_SSH, ITEM_TYPE_TELNET, ITEM_TYPE_JMX
	];

	/**
	 * Item types with mandatory item key.
	 *
	 * @var array
	 */
	protected $item_types_has_key_mandatory = [ITEM_TYPE_ZABBIX, ITEM_TYPE_SIMPLE, ITEM_TYPE_INTERNAL,
		ITEM_TYPE_AGGREGATE, ITEM_TYPE_EXTERNAL, ITEM_TYPE_DB_MONITOR, ITEM_TYPE_HTTPAGENT, ITEM_TYPE_IPMI,
		ITEM_TYPE_SSH, ITEM_TYPE_TELNET, ITEM_TYPE_JMX, ITEM_TYPE_CALCULATED
	];

	/**
	 * Tested item type.
	 *
	 * @var int
	 */
	protected $item_type;

	/**
	 * Tested item's host.
	 *
	 * @var int
	 */
	protected $host;

	/**
	 * Is item testable.
	 *
	 * @var bool
	 */
	protected $is_item_testable;

	/**
	 * @var object
	 */
	protected $preproc_item;

	/**
	 * @var array
	 */
	protected static $preproc_steps_using_prev_value = [ZBX_PREPROC_DELTA_VALUE, ZBX_PREPROC_DELTA_SPEED,
		ZBX_PREPROC_THROTTLE_VALUE, ZBX_PREPROC_THROTTLE_TIMED_VALUE
	];

	protected function checkPermissions() {
		$ret = ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);

		/*
		 * Preprocessing test can be done from mass-update section so host is non mandatory but if it is used, it must
		 * be editable.
		 */
		$hostid = $this->getInput('hostid', false);

		if ($ret && $hostid) {
			$host = API::Host()->get([
				'output' => ['hostid', 'host', 'status', 'available', 'flags', 'proxy_hostid', 'tls_subject',
					'ipmi_available', 'jmx_available', 'snmp_available', 'maintenance_status', 'maintenance_type',
					'ipmi_authtype', 'ipmi_privilege', 'ipmi_username', 'ipmi_password', 'tls_psk_identity', 'tls_psk',
					'tls_issuer', 'tls_connect'
				],
				'hostids' => [$hostid],
				'templated_hosts' => true,
				'editable' => true
			]);

			$this->host = reset($host);
			$ret = (bool) $this->host;
		}

		return $ret;
	}

	/**
	 * Function returns instance of item, item prototype or discovery rule class.
	 *
	 * @param int $test_type
	 *
	 * @return CItem|CItemPrototype|CDiscoveryRule
	 */
	protected function getPreprocessingItemClassInstance($test_type) {
		switch ($test_type) {
			case self::ZBX_TEST_TYPE_ITEM:
				return new CItem;

			case self::ZBX_TEST_TYPE_ITEM_PROTOTYPE:
				return new CItemPrototype;

			case self::ZBX_TEST_TYPE_LLD:
				return new CDiscoveryRule;
		}
	}

	/**
	 * Function returns list of proxies.
	 *
	 * @return array
	 */
	protected function getHostProxies() {
		$proxies = API::Proxy()->get([
			'output' => ['host'],
			'preservekeys' => true
		]);

		CArrayHelper::sort($proxies, [['field' => 'host', 'order' => ZBX_SORT_UP]]);

		foreach ($proxies as &$proxy) {
			$proxy = $proxy['host'];
		}
		unset($proxy);

		return $proxies;
	}

	/**
	 * Function returns array of item specific properties used for item testing.
	 *
	 * @param array $input  Stored user input used to overwrite values retrieved from database.
	 *
	 * @return array
	 */
	protected function getItemTestProperties(array $input) {
		$data = [
			'value_type' => $input['value_type']
		];

		if (!$this->is_item_testable) {
			return $data;
		}

		$data['type'] = $this->item_type;

		$interface_input = [
			'interfaceid' => array_key_exists('interfaceid', $input) ? $input['interfaceid'] : 0
		];

		if (array_key_exists('useip', $input)) {
			$interface_input['useip'] = $input['useip'];
		}

		if (array_key_exists('data', $input) && array_key_exists('port', $input['data'])) {
			$interface_input['port'] = $input['data']['port'];
		}
		elseif (array_key_exists('interface', $input) && array_key_exists('port', $input['interface'])) {
			$interface_input['port'] = $input['interface']['port'];
		}
		elseif (array_key_exists('port', $input)) {
			$interface_input['port'] = $input['port'];
		}

		if (array_key_exists('data', $input) && array_key_exists('address', $input['data'])) {
			$interface_input['address'] = $input['data']['address'];
		}
		elseif (array_key_exists('interface', $input) && array_key_exists('address', $input['interface'])) {
			$interface_input['address'] = $input['interface']['address'];
		}
		elseif (array_key_exists('address', $input)) {
			$interface_input['address'] = $input['address'];
		}

		switch ($this->item_type) {
			case ITEM_TYPE_ZABBIX:
				$data += [
					'key' => array_key_exists('key', $input) ? $input['key'] : null,
					'host' => [
						'tls_subject' => $this->host['tls_subject'],
						'tls_psk_identity' => $this->host['tls_psk_identity'],
						'tls_psk' => $this->host['tls_psk'],
						'tls_issuer' => $this->host['tls_issuer'],
						'tls_connect' => $this->host['tls_connect']
					],
					'interface' => $this->getItemTestInterface($interface_input)
				];

				unset($data['interface']['useip'], $data['interface']['interfaceid']);
				break;

			case ITEM_TYPE_SNMPV1:
			case ITEM_TYPE_SNMPV2C:
			case ITEM_TYPE_SNMPV3:
				switch ($this->getInput('test_type')) {
					case self::ZBX_TEST_TYPE_LLD:
						$item_flag = ZBX_FLAG_DISCOVERY_RULE;
						break;

					case self::ZBX_TEST_TYPE_ITEM_PROTOTYPE;
						$item_flag = ZBX_FLAG_DISCOVERY_PROTOTYPE;
						break;

					default:
						$item_flag = ZBX_FLAG_DISCOVERY_NORMAL;
						break;
				}

				$data += [
					'snmp_oid' => array_key_exists('snmp_oid', $input) ? $input['snmp_oid'] : null,
					'snmp_community' => array_key_exists('snmp_community', $input) ? $input['snmp_community'] : null,
					'flags' => $item_flag,
					'host' => [
						'host' => $this->host['host']
					],
					'interface' => $this->getItemTestInterface($interface_input)
				];

				if ($this->item_type == ITEM_TYPE_SNMPV3) {
					$data += [
						'snmpv3_securityname' => array_key_exists('snmpv3_securityname', $input)
							? $input['snmpv3_securityname']
							: null,
						'snmpv3_contextname' => array_key_exists('snmpv3_contextname', $input)
							? $input['snmpv3_contextname']
							: null,
						'snmpv3_securitylevel' => array_key_exists('snmpv3_securitylevel', $input)
							? $input['snmpv3_securitylevel']
							: ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV
					];

					if ($data['snmpv3_securitylevel'] == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
						$data += [
							'snmpv3_authprotocol' => array_key_exists('snmpv3_authprotocol', $input)
								? $input['snmpv3_authprotocol']
								: null,
							'snmpv3_authpassphrase' => array_key_exists('snmpv3_authpassphrase', $input)
								? $input['snmpv3_authpassphrase']
								: null,
							'snmpv3_privprotocol' => array_key_exists('snmpv3_privprotocol', $input)
								? $input['snmpv3_privprotocol']
								: null,
							'snmpv3_privpassphrase' => array_key_exists('snmpv3_privpassphrase', $input)
								? $input['snmpv3_privpassphrase']
								: null
						];
					}
				}
				break;

			case ITEM_TYPE_INTERNAL:
				$data += [
					'key' => $input['key'],
					'host' => [
						'hostid' => $this->host['hostid'],
						'available' => $this->host['available'],
						'ipmi_available' => $this->host['ipmi_available'],
						'jmx_available' => $this->host['jmx_available'],
						'snmp_available' => $this->host['snmp_available'],
						'maintenance_status' => $this->host['maintenance_status'],
						'maintenance_type' => $this->host['maintenance_type']
					]
				];
				break;

			case ITEM_TYPE_AGGREGATE:
				$data += [
					'key' => $input['key']
				];
				break;

			case ITEM_TYPE_EXTERNAL:
				$data += [
					'key' => $input['key']
				];
				break;

			case ITEM_TYPE_DB_MONITOR:
				$data += [
					'key' => $input['key'],
					'params_ap' => array_key_exists('params_ap', $input) ? $input['params_ap'] : null,
					'username' => array_key_exists('username', $input) ? $input['username'] : null,
					'password' => array_key_exists('password', $input) ? $input['password'] : null
				];
				break;

			case ITEM_TYPE_HTTPAGENT:
				$data += [
					'key' => $input['key'],
					'http_authtype' => array_key_exists('http_authtype', $input)
						? $input['http_authtype']
						: HTTPTEST_AUTH_NONE,
					'follow_redirects' => array_key_exists('follow_redirects', $input) ? $input['follow_redirects'] : 0,
					'headers' => array_key_exists('headers', $input) ? $input['headers'] : [],
					'http_proxy' => array_key_exists('http_proxy', $input) ? $input['http_proxy'] : null,
					'output_format' => array_key_exists('output_format', $input) ? $input['output_format'] : 0,
					'posts' => array_key_exists('posts', $input) ? $input['posts'] : null,
					'post_type' => array_key_exists('post_type', $input) ? $input['post_type'] : ZBX_POSTTYPE_RAW,
					'query_fields' => array_key_exists('query_fields', $input) ? $input['query_fields'] : [],
					'request_method' => array_key_exists('request_method', $input)
						? $input['request_method']
						: HTTPCHECK_REQUEST_GET,
					'retrieve_mode' => array_key_exists('retrieve_mode', $input)
						? $input['retrieve_mode']
						: HTTPTEST_STEP_RETRIEVE_MODE_CONTENT,
					'ssl_cert_file' => array_key_exists('ssl_cert_file', $input) ? $input['ssl_cert_file'] : null,
					'ssl_key_file' => array_key_exists('ssl_key_file', $input) ? $input['ssl_key_file'] : null,
					'ssl_key_password' => array_key_exists('ssl_key_password', $input)
						? $input['ssl_key_password']
						: null,
					'status_codes' => array_key_exists('status_codes', $input) ? $input['status_codes'] : null,
					'timeout' => array_key_exists('timeout', $input) ? $input['timeout'] : null,
					'url' => array_key_exists('url', $input) ? $input['url'] : null,
					'verify_host' => array_key_exists('verify_host', $input) ? $input['verify_host'] : 0,
					'verify_peer' => array_key_exists('verify_peer', $input) ? $input['verify_peer'] : 0
				];

				if ($data['http_authtype'] != HTTPTEST_AUTH_NONE) {
					$data += [
						'http_username' => array_key_exists('http_username', $input) ? $input['http_username'] : null,
						'http_password' => array_key_exists('http_password', $input) ? $input['http_password'] : null
					];
				}
				break;

			case ITEM_TYPE_IPMI:
				$data += [
					'key' => $input['key'],
					'ipmi_sensor' => array_key_exists('ipmi_sensor', $input) ? $input['ipmi_sensor'] : null,
					'interface' => $this->getItemTestInterface($interface_input),
					'host' => [
						'hostid' => $this->host['hostid'],
						'ipmi_authtype' => $this->host['ipmi_authtype'],
						'ipmi_privilege' => $this->host['ipmi_privilege'],
						'ipmi_username' => $this->host['ipmi_username'],
						'ipmi_password' => $this->host['ipmi_password']
					]
				];

				unset($data['interface']['useip'], $data['interface']['interfaceid']);
				break;

			case ITEM_TYPE_SSH:
				$data += [
					'key' => $input['key'],
					'authtype' => array_key_exists('authtype', $input) ? $input['authtype'] : ITEM_AUTHTYPE_PASSWORD,
					'params_es' => array_key_exists('params_es', $input) ? $input['params_es'] : ITEM_AUTHTYPE_PASSWORD,
					'interface' => $this->getItemTestInterface($interface_input),
					'username' => array_key_exists('username', $input) ? $input['username'] : null,
					'password' => array_key_exists('password', $input) ? $input['password'] : null
				];

				if ($data['authtype'] == ITEM_AUTHTYPE_PUBLICKEY) {
					$data += [
						'publickey' => array_key_exists('publickey', $input) ? $input['publickey'] : null,
						'privatekey' => array_key_exists('privatekey', $input) ? $input['privatekey'] : null
					];
				}

				unset($data['interface']['interfaceid'], $data['interface']['useip']);
				break;

			case ITEM_TYPE_TELNET:
				$data += [
					'key' => $input['key'],
					'params_es' => array_key_exists('params_es', $input) ? $input['params_es'] : null,
					'publickey' => array_key_exists('publickey', $input) ? $input['publickey'] : null,
					'privatekey' => array_key_exists('privatekey', $input) ? $input['privatekey'] : null,
					'interface' => $this->getItemTestInterface($interface_input)
				];

				unset($data['interface']['interfaceid'], $data['interface']['useip']);
				break;

			case ITEM_TYPE_JMX:
				$data += [
					'key' => $input['key'],
					'jmx_endpoint' => array_key_exists('jmx_endpoint', $input) ? $input['jmx_endpoint'] : null,
					'username' => array_key_exists('username', $input) ? $input['username'] : null,
					'password' => array_key_exists('password', $input) ? $input['password'] : null
				];
				break;

			case ITEM_TYPE_CALCULATED:
				$data += [
					'key' => $input['key'],
					'params_f' => array_key_exists('params_f', $input) ? $input['params_f'] : null,
					'host' => [
						'host' => $this->host['host']
					]
				];
				break;

			case ITEM_TYPE_SIMPLE:
				$data += [
					'key' => $input['key'],
					'interface' => $this->getItemTestInterface($interface_input),
					'username' => array_key_exists('username', $input) ? $input['username'] : null,
					'password' => array_key_exists('password', $input) ? $input['password'] : null
				];

				unset($data['interface']['useip'], $data['interface']['interfaceid'],  $data['interface']['port']);
				break;
		}

		return $data;
	}

	/**
	 * Check if item belongs to host and select and resolve interface properties. Leave fields empty otherwise.
	 *
	 * @var array $inputs  Stored user input used to overwrite values retrieved from database.
	 *
	 * @param array $interface_data
	 */
	protected function getItemTestInterface(array $inputs) {
		$interface_data = [
			'address' => '',
			'port' => '',
			'interfaceid' => 0,
			'useip' => INTERFACE_USE_DNS
		];

		if (($this->host['status'] == HOST_STATUS_MONITORED || $this->host['status'] == HOST_STATUS_NOT_MONITORED)
				&& array_key_exists('interfaceid', $inputs)
				&& in_array($this->item_type, $this->items_require_interface)) {
			$interface = array_key_exists('interfaceid', $inputs)
				? API::HostInterface()->get([
					'output' => ['hostid', 'type', 'dns', 'ip', 'port', 'main', 'useip'],
					'interfaceids' => $inputs['interfaceid'],
					'hostids' => $this->host['hostid']
				])
				: null;

			if ($interface) {
				$interface = CMacrosResolverHelper::resolveHostInterfaces($interface);

				$interface_data = [
					'address' => ($interface[0]['useip'] == INTERFACE_USE_IP)
						? $interface[0]['ip']
						: $interface[0]['dns'],
					'port' => $interface[0]['port'],
					'useip' => $interface[0]['useip'],
					'interfaceid' => $interface[0]['interfaceid']
				];
			}
		}

		foreach ($inputs as $key => $value) {
			$interface_data[$key] = $value;
		}

		return $interface_data;
	}

	/**
	 * Function returns human readable time used for previous time field in item test.
	 *
	 * @return string
	 */
	protected function getPrevTime() {
		$time_change = max($this->getInput('time_change', 1), 1);

		if ($time_change >= SEC_PER_DAY) {
			$n = floor($time_change / SEC_PER_DAY);
			return 'now-'.$n.'d';
		}
		elseif ($time_change >= SEC_PER_HOUR * 5) {
			$n = floor($time_change / SEC_PER_HOUR);
			return 'now-'.$n.'h';
		}
		elseif ($time_change >= SEC_PER_MIN * 5) {
			$n = floor($time_change / SEC_PER_MIN);
			return 'now-'.$n.'m';
		}
		else {
			return 'now-'.$time_change.'s';
		}
	}

	/**
	 * Function to unset unspecified values before sending 'get value' request to server.
	 *
	 * @param array $data  Data array containing all parameters prepared to be sent to server.
	 *
	 * @return array
	 */
	protected function unsetEmptyValues(array $data) {
		foreach ($data as $key => $value) {
			if ($key === 'host' && is_array($value)) {
				$data[$key] = $this->unsetEmptyValues($value);

				if (!$data[$key]) {
					unset($data[$key]);
				}
			}
			elseif ($key === 'interface') {
				continue;
			}
			elseif ($value === '' || $value === null) {
				unset($data[$key]);
			}
		}

		return $data;
	}
}
