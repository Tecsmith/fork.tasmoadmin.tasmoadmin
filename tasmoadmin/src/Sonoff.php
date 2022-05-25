<?php

namespace TasmoAdmin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise;
use stdClass;

/**
 * Class Sonoff
 */
class Sonoff
{
    private Client $client;

    private DeviceRepository $deviceRepository;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
                'connect_timeout' => 5,
                'timeout' => 5,
            ]);
        $this->deviceRepository = new DeviceRepository(_CSVFILE_, _TMPDIR_);
    }

    public function getAllStatus(Device $device): stdClass
    {
        return $this->doRequest($device, "Status 0");
    }

    private function doRequest(Device $device, string $cmnd, int $try = 1): stdClass
    {
        $url = $this->buildCmndUrl($device, $cmnd);

        try {
            $result = $this->client->get($url)->getBody();
        } catch (GuzzleException $exception) {
            $result = new stdClass();
            $result->ERROR = __("CURL_ERROR", "API") . " => " . $exception->getMessage();
            return $result;
        }

        $data = json_decode($result->getContents());

        if (json_last_error() == JSON_ERROR_CTRL_CHAR) {  // https://github.com/TasmoAdmin/TasmoAdmin/issues/78
            $result = preg_replace('/[[:cntrl:]]/', '', $result);
            $data = json_decode($result);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $result = $this->fixJsonFormatv5100($result);
            $data = json_decode($result);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $result = $this->fixJsonFormatv8500($result);
            $data = json_decode($result);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = new stdClass();
            $data->ERROR = __("JSON_ERROR", "API") . " => " . json_last_error() . ": " . json_last_error_msg();
            $data->ERROR .= "<br/><strong>" . __("JSON_ERROR_CONTACT_DEV", "API", [$result]) . "</strong>";
            $data->ERROR .= "<br/>" . __("JSON_ANSWER", "API") . " => " . print_r($data, TRUE);

        }
        $skipWarning = FALSE;
        if (strpos($cmnd, "Backlog") !== FALSE) {
            $skipWarning = TRUE;
        }

        if (!$skipWarning && isset($data->WARNING) && !empty($data->WARNING) && $try === 1) {
            $try++;
            //set web log level 2 and try again
            $webLog = $this->setWebLog($device, 2, $try);
            if (!isset($webLog->WARNING) && empty($webLog->WARNING)) {
                $data = $this->doRequest($device, $cmnd, $try);
            }
        } else if (empty($data->ERROR)) {
            $data = $this->compatibility($data);
        }

        $data = $this->stateTextsDetection($data);

        return $data;
    }

    public function buildCmndUrl(Device $device, string $cmnd): string
    {
        $start = "?";
        if (!empty($device->password)) {
            $start = "?user=" . urlencode($device->username) . "&password=" . urlencode($device->password) . "&";
        }

        return "http://" . $device->ip . "/cm" . $start . "cmnd=" . urlencode($cmnd);
    }

    /**
     *
     * This fixes wrong formated json answer form Tasmota Version 5.10.0
     * Example wrong format: dev/json_error_5100.json
     *
     * @param $string
     *
     * @return string
     */
    private function fixJsonFormatV5100(string $string): string
    {
        $string = substr($string, strpos($string, "STATUS = "));
        if (strpos($string, "POWER = ") !== FALSE) {
            $string = substr($string, strpos($string, "{"));
            $string = substr($string, 0, strrpos($string, "}") + 1);
        }
        if (strpos($string, "ERGEBNIS = ") !== FALSE) {
            $string = substr($string, strpos($string, "{"));
            $string = substr($string, 0, strrpos($string, "}") + 1);
        }
        if (strpos($string, "RESULT = ") !== FALSE) {
            $string = substr($string, strpos($string, "{"));
            $string = substr($string, 0, strrpos($string, "}") + 1);
        }


        $remove = [
            PHP_EOL,
            "\n",
            "STATUS = ",
            "}STATUS1 = {",
            "}STATUS2 = {",
            "}STATUS3 = {",
            "}STATUS4 = {",
            "}STATUS5 = {",
            "}STATUS6 = {",
            "}STATUS7 = {",
            "}in = {",
            "}STATUS8 = {",
            "}STATUS9 = {",
            "}STATUS10 = {",
            "}STATUS11 = {",
            "STATUS2 = ",
            ":nan,",
            ":nan}",
        ];
        $replace = [
            "",
            "",
            "",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            ",",
            "",
            ":\"NaN\",",
            ":\"NaN\"}",
        ];

        $string = str_replace($remove, $replace, $string);

        //remove everything befor ethe first {
        $string = strstr($string, '{');

        //			var_dump( $string );
        //			var_dump( json_decode( $string ) );
        //			var_dump( json_last_error_msg() );
        //			die();

        return $string;
    }

    /**
     *
     * This fixes wrong formatted json answer form Tasmota Version 8.5.0.x
     * Example wrong format: dev/json_error_8500.json
     *
     * Shutters missed a } at the end
     * https://github.com/TasmoAdmin/TasmoAdmin/issues/398
     *
     * @param $string
     *
     * @return mixed
     */
    private function fixJsonFormatv8500(string $string): string
    {
        $string .= "}";

        return $string;
    }

    private function setWebLog(Device $device, int $level = 2, int $try = 1): stdClass
    {
        $cmnd = "Weblog " . $level;

        return $this->doRequest($device, $cmnd, $try);
    }

    public function compatibility(stdClass $status): stdClass
    {
        /**
         * < 5.12.0
         * $status->StatusNET->IP
         * >= 5.12.0
         * $status->StatusNET->IPAddress
         * https://github.com/TasmoAdmin/TasmoAdmin/issues/107
         **/
        if (!empty($status->StatusNET->IP)) {
            $status->StatusNET->IPAddress = $status->StatusNET->IP;
        }


        return $status;
    }

    public function stateTextsDetection($status)
    {
        /**
         * v6.2.0.2 2018-09-04
         *  MQTT Changed Statetext is send in JSON, this is fail cuz it can be translated and not detected by other softwares.
         *
         * This function tries to detect the state by hardcoded keywords.
         */

        $offArray = explode(
            ", ",

            strtolower(
                ""

                /**
                 * EN
                 */ . "off, down, offline, out, "

                /**
                 * DE
                 */ . "aus, unten, runter, schließen, schliessen, zu, "

                /**
                 * PL
                 */ . "z, poniżej, ponizej, blisko, do, zamknięte, zamkniete"
            )
        );
        $onArray = explode(
            ", ",

            strtolower(
                ""

                /**
                 * EN
                 */ . "on, up, online, in, "

                /**
                 * DE
                 */ . "an, oben, hoch, öffnen, oeffnen, offen, "

                /**
                 * PL
                 */ . "do, powyżej, powyzej, wysoki, otwarte"
            )
        );


        $state = NULL;

        //status 0 request for 1 relais
        if (isset($status->StatusSTS->POWER)) {
            $state = $status->StatusSTS->POWER;
            if (isset($status->StatusSTS->POWER->STATE)) {
                $state = $status->StatusSTS->POWER->STATE;
            }
            //try to detect OFF
            if (in_array(strtolower($state), $offArray)) {
                $state = "OFF";
            } elseif (in_array(strtolower($state), $onArray)) {
                $state = "ON";
            }


            if (!empty($state)) {
                if (isset($status->StatusSTS->POWER->STATE)) {
                    $status->StatusSTS->POWER->STATE = $state;
                } else {
                    $status->StatusSTS->POWER = $state;
                }
            }
        }

        //toggle request for 1 relais
        if (isset($status->POWER)) {
            $state = $status->POWER;
            //try to detect OFF
            if (in_array(strtolower($state), $offArray)) {
                $state = "OFF";
            } elseif (in_array(strtolower($state), $onArray)) {
                $state = "ON";
            }

            if (!empty($state)) {
                $status->POWER = $state;
            }
        }

        $i = 1;
        $power = "POWER" . $i;

        //status 0 request for multi relais
        while (isset($status->StatusSTS->$power)) {
            $state = NULL;


            $state = $status->StatusSTS->$power;
            if (isset($status->StatusSTS->$power->STATE)) {
                $state = $status->StatusSTS->$power->STATE;
            }
            //try to detect OFF
            if (in_array(strtolower($state), $offArray)) {
                $state = "OFF";
            } elseif (in_array(strtolower($state), $onArray)) {
                $state = "ON";
            }

            if (!empty($state)) {
                if (isset($status->StatusSTS->$power->STATE)) {
                    $status->StatusSTS->$power->STATE = $state;
                } else {
                    $status->StatusSTS->$power = $state;
                }
            }


            $i++;
            $power = "POWER" . $i;
        }


        $i = 1;
        $power = "POWER" . $i;

        //toggle request for multi relais
        while (isset($status->$power)) {
            $state = NULL;


            $state = $status->$power;
            if (isset($status->$power->STATE)) {
                $state = $status->$power->STATE;
            }

            //try to detect OFF
            if (in_array(strtolower($state), $offArray)) {
                $state = "OFF";
            } elseif (in_array(strtolower($state), $onArray)) {
                $state = "ON";
            }

            if (!empty($state)) {
                if (isset($status->$power->STATE)) {
                    $status->$power->STATE = $state;
                } else {
                    $status->$power = $state;
                }
                $status->$power = $state;
            }


            $i++;
            $power = "POWER" . $i;
        }


        return $status;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getNTPStatus($device)
    {
        $cmnd = "NtpServer1";


        $status = $this->doRequest($device, $cmnd);
        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }

        return $status;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getFullTopic($device)
    {
        $cmnd = "FullTopic";


        $status = $this->doRequest($device, $cmnd);
        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }

        if (!empty($status->ERROR)) {
            return "";
        }

        return $status->FullTopic;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getSwitchTopic($device)
    {
        $cmnd = "SwitchTopic";


        $status = $this->doRequest($device, $cmnd);

        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }

        if (!empty($status->ERROR)) {
            return "";
        }

        return $status->SwitchTopic;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getMqttRetry($device)
    {
        $cmnd = "MqttRetry";


        $status = $this->doRequest($device, $cmnd);
        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }

        if (!empty($status->ERROR)) {
            return "";
        }

        return $status->MqttRetry;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getTelePeriod($device)
    {
        $cmnd = "TelePeriod";


        $status = $this->doRequest($device, $cmnd);
        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }

        if (!empty($status->ERROR)) {
            return "";
        }

        return $status->TelePeriod;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getSensorRetain($device)
    {
        $cmnd = "SensorRetain";


        $status = $this->doRequest($device, $cmnd);
        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }

        if (!empty($status->ERROR)) {
            return "";
        }

        return $status->SensorRetain;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getMqttFingerprint($device)
    {
        $cmnd = "MqttFingerprint";


        $status = $this->doRequest($device, $cmnd);
        if (!empty($status->Command) && $status->Command === "Unknown") {
            return "";
        }
        if (!empty($status->ERROR)) {
            return "";
        }

        if (empty($status->MqttFingerprint)) {
            return "";
        }

        return $status->MqttFingerprint;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getPrefixe($device)
    {
        $cmnds = ["Prefix1", "Prefix2", "Prefix3"];

        $status = new stdClass();
        foreach ($cmnds as $cmnd) {
            $tmp = $this->doRequest($device, $cmnd);

            if (!empty($tmp->Command) && $tmp->Command === "Unknown") {
                $status->$cmnd = "";
            } else {

                if (!empty($status->ERROR)) {
                    $status->$cmnd = "";
                } else {
                    $status->$cmnd = $tmp->$cmnd;
                }
            }

        }

        unset($tmp);

        return $status;
    }

    /**
     * @param $ip
     *
     * @return mixed
     */
    public function getStateTexts($device)
    {
        $cmnds = ["StateText1", "StateText2", "StateText3", "StateText4"];

        $status = new stdClass();
        foreach ($cmnds as $cmnd) {
            $tmp = $this->doRequest($device, $cmnd);
            if (!empty($tmp->Command) && $tmp->Command === "Unknown") {
                $status->$cmnd = "";
            } else {
                if (!empty($status->ERROR)) {
                    $status->$cmnd = "";
                } else {
                    $status->$cmnd = $tmp->$cmnd;
                }
            }
        }

        unset($tmp);

        return $status;
    }

    public function saveConfig(Device $device, string $backlog): stdClass
    {
        return $this->doRequest($device, $backlog);
    }

    public function doAjax($deviceId)
    {
        $device = $this->getDeviceById($deviceId);

        if ($device === null) {
            $response = new stdClass();
            $response->ERROR = sprintf("No devices found with ID: %d", $deviceId);
            return $response;
        }

        $url = $this->buildCmndUrl($device, urldecode($_REQUEST["cmnd"]));

        try {
            $response = $this->client->request('GET', $url, ['timeout' => 8]);

            return $this->processResult(json_decode($response->getBody()));
        } catch (GuzzleException $exception) {
            $result = new stdClass();
            $result->ERROR = $exception->getMessage();
            return $result;
        }
    }

    public function getDeviceById($id = null): ?Device
    {
        return $this->deviceRepository->getDeviceById($id);
    }

    public function doAjaxAll()
    {
        ini_set("max_execution_time", "99999999999");

        $devices = $this->getDevices();
        $cmnd = "status 0";

        $promises = [];
        foreach ($devices as $device) {
            $url = $this->buildCmndUrl($device, $cmnd);
            $promises[$device->id] = $this->client->getAsync($url, [
                'timeout' => 8,
            ]);
        }

        $responses = Promise\settle($promises)->wait();

        $results = [];
        foreach ($responses as $deviceId => $response) {
            if ($response['state'] === 'rejected') {
                continue;
            }

            $result = json_decode($response['value']->getBody()->getContents());

            $results[$deviceId] = $this->processResult($result);
        }

        return $results;
    }

    public function setDeviceValue(string $id, $field = null, $value = null): ?Device
    {
        return $this->deviceRepository->setDeviceValue($id, $field, $value);
    }

    /**
     * @param string $orderBy
     * @return Device[]
     */
    public function getDevices(string $orderBy = "position"): array
    {
        $devices = $this->deviceRepository->getDevices();

        if ($orderBy === "position") {
            $devicesTmp = [];
            $update = false;
            foreach ($devices as $device) {
                if ($device->position === "") {
                    $device->position = 1;
                    $update = true;
                }
                while (isset($devicesTmp[$device->position])) {
                    $device->position++;
                }
                if ($update) {
                    $this->deviceRepository->setDeviceValue($device->id, "position", $device->position);
                }
                $devicesTmp[$device->position] = $device;
            }
            ksort($devicesTmp);
            $devices = $devicesTmp;
            unset($devicesTmp);
        }

        return $devices;
    }

    public function search($urls = [])
    {
        $result = [];
        ini_set("max_execution_time", "99999999999");

        $urlsClone = $urls;

        // make sure the rolling window isn't greater than the # of urls
        $rolling_window = 10;
        $rolling_window = (count($urls) < $rolling_window) ? count($urls) : $rolling_window;
        $master = curl_multi_init();
        // $curl_arr = array();
        // add additional curl options here
        $options = [
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
        ];
        // start the first batch of requests

        for ($i = 0; $i < $rolling_window; $i++) {
            $ch = curl_init();
            $options[CURLOPT_URL] = $urlsClone[$i];
            curl_setopt_array($ch, $options);
            curl_multi_add_handle($master, $ch);
        }
        $i--;

        do {
            while (($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM) {
                ;
            }
            if ($execrun != CURLM_OK) {
                break;
            }
            // a request was just completed -- find out which one
            while ($done = curl_multi_info_read($master)) {
                $info = curl_getinfo($done['handle']);
                $output = curl_multi_getcontent($done['handle']);

                if (!$output) {

                } else {
                    $data = json_decode($output);


                    if (json_last_error() == JSON_ERROR_CTRL_CHAR) {  // https://github.com/TasmoAdmin/TasmoAdmin/issues/78
                        $output = preg_replace('/[[:cntrl:]]/', '', $output);
                        $data = json_decode($output);
                    }

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $outputTmp = $this->fixJsonFormatv5100($output);
                        $data = json_decode($outputTmp);
                    }
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $outputTmp = $this->fixJsonFormatv8500($output);
                        $data = json_decode($outputTmp);
                    }

                    if (json_last_error() !== JSON_ERROR_NONE) {
                    } else {

                        if (empty($data->ERROR)) {
                            $data = $this->compatibility($data);
                            $data = $this->stateTextsDetection($data);
                        }
                        $result[] = $data;
                    }
                    unset($outputTmp);
                }

                // start a new request (it's important to do this before removing the old one)
                if (count($urls) >= $i + 1) {
                    $ch = curl_init();
                    $options[CURLOPT_URL] = $urlsClone[$i++];  // increment i


                    curl_setopt_array($ch, $options);
                    curl_multi_add_handle($master, $ch);
                }
                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);
                curl_close($done["handle"]);
            }
        } while ($running);
        curl_multi_close($master);

        unset($urlsClone);
        unset($urls);


        ini_set("max_execution_time", "60");

        return $result;
    }

    public function decodeOptions($options)
    {
        if (empty($options)) {
            return FALSE;
        }
        $a_setoption = [
            //Tasmota\tools\decode-status.py
            "Save power state and use after restart",
            "Restrict button actions to single, double and hold",
            "Show value units in JSON messages",
            "MQTT enabled",
            "Respond as Command topic instead of RESULT",
            "MQTT retain on Power",
            "MQTT retain on Button",
            "MQTT retain on Switch",
            "Convert temperature to Fahrenheit",
            "MQTT retain on Sensor",
            "MQTT retained LWT to OFFLINE when topic changes",
            "Swap Single and Double press Button",
            "Do not use flash page rotate",
            "Button single press only",
            "Power interlock mode",
            "Do not allow PWM control",
            "Reverse clock",
            "Allow entry of decimal color values",
            "CO2 color to light signal",
            "HASS discovery",
            "Do not control Power with Dimmer",
            "Energy monitoring while powered off",
            "MQTT serial",
            "Rules",
            "Rules once mode",
            "KNX",
            "Use Power device index on single relay devices",
            "KNX enhancement",
            "",
            "",
            "",
            "",
        ];

        if (is_array($options)) {
            $options = $options[0];
        }

        $decodedOptopns = new stdClass();

        $options = intval($options, 16);
        foreach ($a_setoption as $i => $iValue) {
            $optionV = ($options >> $i) & 1;
            $SetOPtion = "SetOption" . $i;
            $decodedOptopns->$SetOPtion = new stdClass();
            $decodedOptopns->$SetOPtion->desc = $iValue;
            $decodedOptopns->$SetOPtion->value = $optionV;
        }


        return $decodedOptopns;
    }

    private function processResult(string $result): stdClass
    {
        if (json_last_error() === JSON_ERROR_CTRL_CHAR) {  // https://github.com/TasmoAdmin/TasmoAdmin/issues/78
            $result = preg_replace('/[[:cntrl:]]/', '', $result);
            $result = json_decode($result);
        } elseif (json_last_error() !== JSON_ERROR_NONE) {
            $result = json_decode($this->fixJsonFormatv5100($result));
        } elseif (json_last_error() !== JSON_ERROR_NONE) {
            $result = json_decode($this->fixJsonFormatv8500($result));
        } elseif (json_last_error() !== JSON_ERROR_NONE) {
            $result = new stdClass();
            $result->ERROR = __("JSON_ERROR", "API")
                . " => "
                . json_last_error()
                . ": "
                . json_last_error_msg();
            $result->ERROR .= "<br/><strong>"
                . __("JSON_ERROR_CONTACT_DEV", "API", [$result])
                . "</strong>";
            $result->ERROR .= "<br/>" . __("JSON_ANSWER", "API") . " => " . print_r($result, TRUE);
        }

        if (empty($result->ERROR)) {
            $result = $this->compatibility($result);
        }

        return $this->stateTextsDetection($result);
    }
}


