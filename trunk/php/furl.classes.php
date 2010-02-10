<?php

// TODO
// - List of proxies to try.
// - Keep track of service failures, remove ones that fail more than X% of the time.
// - Add more randomized HTTP headers to requests (user agent especially).
// - furl file vs. furl data
// - Redundant storage (optional)
// - Smart fetching -- use Location header if possible, then scrape URLs from body and test each in order of length desc.
// - Indexless operation -- doubly-linked list, store sequence info in data.
// - Smart store response parsing.
// - Smart storing.
// - Threading -- multiple child processes storing/retrieving chunks in parallel.
// - Add a way to simply test a service.
// - Command-line parameters: minimum sleep between requests, max chunk size, save to different filename, etc.

class Furler {

    var $Services;

    function Furler ($serviceDirectory = './services') {
        $this->Services = array();
        $this->loadServices($serviceDirectory);
    }

    function furl ($something) {
    	if (file_exists($something)) return $this->furlFile($something);
    	else return $this->furlData($something);
    }

    function furlData ($data) {
    	// Not yet implemented.
    }

    function furlFile ($file) {
        if (!file_exists($file)) {
            trigger_error("File $file doesn't exist", E_USER_ERROR);
            return false;
        }

        if (!is_readable($file)) {
            trigger_error("File $file is not readable", E_USER_ERROR);
            return false;
        }

        if (!filesize($file)) trigger_error("File $file is empty", E_USER_WARNING);

        shuffle($this->Services);

        $fh = fopen($file, 'rb');
        if (!$fh) {
            trigger_error("Can't open file $file for reading", E_USER_ERROR);
            return false;
        }

        $short_urls = array();
        $chunk_number = 1;
        while (!feof($fh)) {
            $spentServices = array();
            $shorter = 0;
            $attempts = 10;
            $success = false;
            $fpos = ftell($fh);
            while (count($spentServices) < count($this->Services) and !$success) {
                // Get the next non-spent service from our list.
                $service = $this->Services[$chunk_number % count($this->Services)];
                while (in_array($service->Name, $spentServices)) $service = $this->Services[($chunk_number + 1) % count($this->Services)];

                // Get a chunk approximately the right size for this service.
                $max_size = $service->getConfigOption('service', 'max-chunk-size');
                if (empty($max_size)) $max_size = 2000;

                do {
                    $max_size -= $shorter;
                    if ($max_size <= 50) {
                        trigger_error("Trying to read too small a chunk", E_USER_WARNING);
                        continue 3;
                    }
                    fseek($fh, $fpos);

                    // Make the chunk smaller by some random amount.
                    $chunk = fread($fh, intval(($max_size - 50) * 0.6 * (1 - (1 / rand(5,20)))));

                    // If we can save some bytes using compression, do it.
                    $compressed = gzcompress('D'.$chunk, 9);
                    if (strlen($compressed) < strlen($chunk)) {
                        trigger_error("Storing compressed data (" . strlen($compressed) . " bytes versus " . strlen($chunk) . " bytes)", E_USER_NOTICE);
                        $chunk = 'C'.$compressed;
                    } else $chunk = 'D'.$chunk;

                    $shorter += 50;
                } while (strlen($chunk) >= $max_size);

                if ($attempts) {
                    $response = $service->store($chunk);
                    if ($response == 'BADSERVICE') {
                        trigger_error("Couldn't store chunk via service {$service->Name}", E_USER_WARNING);
                        $attempts = 10;
                        $spentServices[] = $service->Name;
                        continue;
                    } elseif ($response == 'TOOLARGE') {
                        trigger_error("Chunk too large for service {$service->Name}", E_USER_WARNING);
                        $shorter += 50;
                        $attempts--;
                        continue;
                    } elseif ($response) {
                        $short_urls[] = str_replace('http://', '', $response);
                        trigger_error("Stored chunk $chunk_number on {$service->Host} as " . $short_urls[count($short_urls) - 1], E_USER_NOTICE);
                        print "$chunk_number: Stored " . strlen($chunk) . " bytes of data on {$service->Name} as " . $short_urls[count($short_urls) - 1] . "\n";
                        $success = true;
                    } else {
                        trigger_error("Failed to store chunk $chunk_number on {$service->Host}", E_USER_WARNING);
                        $attempts--;
                        continue;
                    }
                } else {
                    trigger_error("Failed to store chunk $chunk_number on {$service->Host} after 10 attempts", E_USER_WARNING);
                    $spentServices[] = $service->Name;
                    $attempts = 10;
                    continue;
                }
            }

            if ($success) {
                $chunk_number++;
                sleep(rand(1,3));
            } else {
                trigger_error("Failed to store chunk $chunk_number on any service", E_USER_ERROR);
                exit;
            }
        }

        $md5 = md5_file($file);
        $filename = basename($file);

        $next_index_chunk = false;
        $index_chunk_number = 1;
        while (count($short_urls)) {
            $spentServices = array();
            $shorter = 0;
            $attempts = 10;
            $success = false;
            while (count($spentServices) < count($this->Services) and !$success) {
                // Get the next non-spent service from our list.
                $service = $this->Services[$chunk_number % count($this->Services)];
                while (in_array($service->Name, $spentServices)) $service = $this->Services[($chunk_number + 1) % count($this->Services)];

                // Get a chunk approximately the right size for this service.
                $max_size = $service->getConfigOption('service', 'max-chunk-size');
                if (empty($max_size)) $max_size = 2000;

                do {
                    $short_urls_copy = $short_urls; // Don't modify the master list, in case this iteration fails.
                    $max_size -= $shorter;
                    $index_pieces = array();

                    // Keep grabbing URLs from the short_urls list while the total size is less than a semi-random
                    // number smaller than this service's max chunk size.
                    while (strlen(implode(';', $index_pieces)) < (intval(($max_size - 20) * 0.6 * (1 - (1 / rand(5,20))))) - 34 - strlen($filename) and count($short_urls_copy)) $index_pieces[] = array_pop($short_urls_copy);

                    // Reverse the list of pieces -- we're popping them off the end of the list, so they're already
                    // last-chunk-first, and we need to swap that so they're retrieved in the right order. In the future,
                    // each chunk could encode its position in the file so that they could be retrieved in any order and
                    // reassembled on the fly.
                    $index_pieces = array_reverse($index_pieces);
                    $index_chunk = "$filename;$md5;" . implode(';', $index_pieces);

                    // If we're not the last chunk of index, append the next.
                    if ($next_index_chunk) $index_chunk .= ";$next_index_chunk";

                    // If we can save some bytes using compression, do it.
                    $compressed = gzcompress('I'.$index_chunk, 9);
                    if (strlen($compressed) < strlen($index_chunk)) {
                        trigger_error("Storing compressed data (" . strlen($compressed) . " bytes versus " . strlen($index_chunk) . " bytes)", E_USER_NOTICE);
                        $index_chunk = 'C'.$compressed;
                    } else $index_chunk = 'I'.$index_chunk;

                    $shorter += 50;
                } while (strlen($index_chunk) >= $max_size);

                if ($attempts) {
                    $response = $service->store($index_chunk);
                    if ($response == 'BADSERVICE') {
                        trigger_error("Couldn't store index chunk via service {$service->Name}", E_USER_WARNING);
                        $attempts = 10;
                        $spentServices[] = $service->Name;
                        continue;
                    } elseif ($response == 'TOOLARGE') {
                        trigger_error("Index chunk too large for service {$service->Name}", E_USER_WARNING);
                        $shorter += 50;
                        $attempts--;
                        continue;
                    } elseif ($response) {
                        $next_index_chunk = str_replace('http://', '', $response);
                        trigger_error("Stored index chunk $chunk_number on {$service->Host} as $next_index_chunk", E_USER_NOTICE);
                        print "$chunk_number: Stored " . strlen($index_chunk) . " bytes of index data on {$service->Name} as $next_index_chunk\n";
                        $success = true;
                    } else {
                        trigger_error("Failed to store index chunk $chunk_number on {$service->Host}", E_USER_WARNING);
                        $attempts--;
                        continue;
                    }
                } else {
                    trigger_error("Failed to store index chunk $chunk_number on {$service->Host} after 10 attempts", E_USER_WARNING);
                    $spentServices[] = $service->Name;
                    $attempts = 10;
                    continue;
                }
            }

            if ($success) {
                $chunk_number++;
                $index_chunk_number++;
                $short_urls = $short_urls_copy;
                sleep(rand(1,3));
            } else {
                trigger_error("Failed to store index chunk $chunk_number on any service", E_USER_ERROR);
                exit;
            }
        }

        print "Furled at http://$next_index_chunk\n";
        return $next_index_chunk;
    }

    function unfurl ($url) {
        list ($protocol, $junk, $host, $uri) = explode('/', $url);
        foreach ($this->Services as $service) if ($service->Host == $host or $service->Host == 'www.' . $host) break;
        if ($service->Host != $host and $service->Host != 'www.' . $host) {
            trigger_error("Couldn't find service for host $host", E_USER_ERROR);
            return false;
        }

        $data = $service->fetch($url);

        $type = substr($data, 0, 1);
        $index = substr($data, 1);

        if ($type == 'C') {
            $index = gzuncompress($index);
            $type = substr($index, 0, 1);
            $index = substr($index, 1);
        }

        if ($type !== 'I') {
            trigger_error("Data at $url is not a valid furl index.", E_USER_ERROR);
            return false;
        } else {
            $items = explode(';', $index);

            $filename = array_shift($items);
            $md5 = array_shift($items);

            $fh = fopen($filename, 'wb');

            if (!$fh) {
                trigger_error("Couldn't open filehandle for $filename", E_USER_ERROR);
                return false;
            }

            for ($i = 0; $i < count($items); $i++) {
                $next_url = 'http://' . $items[$i];
                print "Getting data from $next_url.\n";

                list ($host, $uri) = explode('/', $items[$i]);

                foreach ($this->Services as $service) if (strtolower($service->Host) == strtolower($host) or strtolower($service->Host) == 'www.' . strtolower($host)) break;
                if (strtolower($service->Host) != strtolower($host) and strtolower($service->Host) != strtolower('www.' . $host)) {
                    trigger_error("Couldn't find service for host $host", E_USER_ERROR);
                    fclose($fh);
                    return false;
                }

                $data = $service->fetch($next_url);

                $type = substr($data, 0, 1);
                $data = substr($data, 1);

                if ($type === 'C') {
                    $orig_size = strlen($data);
                    $data = gzuncompress($data);
                    trigger_error("Decompressed data ($orig_size bytes to " . strlen($data) . " bytes)", E_USER_NOTICE);
                    $type = substr($data, 0, 1);
                    $data = substr($data, 1);
                }

                if ($type === 'D') {
                    $ret = fwrite($fh, $data, strlen($data));
                    $attempt = 1;
                    while ($ret === false and $attempt < 5) {
                        trigger_error("Attempt $attempt: Couldn't write chunk!", E_USER_WARNING);
                        $ret = fwrite($fh, $data, strlen($data));
                    }
                    if ($ret === false) {
                        trigger_error("Failed to write chunk after $attempt attempts.\n", E_USER_ERROR);
                        fclose($fh);
                        return false;
                    } else print "Wrote $ret bytes.\n";
                } elseif ($type === 'I') {
                    $newitems = explode(';', $data);
                    $filename = array_shift($newitems);
                    $md5 = array_shift($newitems);
                    trigger_error("Adding " . count($newitems) . " more chunks to list", E_USER_NOTICE);
                    array_splice($items, count($items), 0, $newitems);
                } else {
                    trigger_error("Invalid data chunk at $next_url (Type key is $type).", E_USER_ERROR);
                    fclose($fh);
                    return false;
                }

                sleep(rand(1,3));
            }

            fclose($fh);
            print "Unfurled $url to file $filename\n";

            $file_md5 = md5_file($filename);
            if ($md5 != $file_md5) trigger_error("File $filename MD5 does not match stored MD5", E_USER_WARNING);

            return $filename;
        }
    }

    function loadServices ($servicePath) {
        if (!file_exists($servicePath)) {
            trigger_error("Service definition path $servicePath doesn't exist.", E_USER_ERROR);
            return false;
        }

        if (!is_dir($servicePath)) {
            trigger_error("Service definition path $servicePath is not a directory.", E_USER_ERROR);
            return false;
        }

        $services = glob("$servicePath/*");

        if (!count($services)) {
            trigger_error("Service definition path $servicePath contains no services.", E_USER_ERROR);
            return false;
        }

        foreach ($services as $service) {
            $s = new FurlService($service);
            if (!empty($s->Host)) $this->Services[] = $s;
        }
    }

}

class FurlService {

    var $Name;
    var $Host;
    var $MaxChunkSize;
    var $Config;

    function FurlService ($serviceFile) {
        $this->Config = array();
        return $this->readConfigFile($serviceFile);
    }

    function readConfigFile ($service) {
        trigger_error("Attempting to read service file $service", E_USER_NOTICE);

        if (!file_exists($service)) {
            trigger_error("Service definition file $service doesn't exist.", E_USER_WARNING);
            return false;
        }

        if (!is_file($service)) {
            trigger_error("$service is a not a regular file", E_USER_WARNING);
            return false;
        }

        if (!is_readable($service)) {
            trigger_error("Service definition file $service is not readable.", E_USER_WARNING);
            return false;
        }

        if (!filesize($service)) trigger_error("Service definition file $service is empty.", E_USER_WARNING);

        $this->Name = basename($service);

        $required = array('service' => array('host' => false, 'max-chunk-size' => false),
                          'store'   => array('endpoint' => false, 'urlparam' => false),
                          'fetch'   => array('endpoint' => false));

        $contents = file_get_contents($service);
        $lines = explode("\n", $contents);

        $in_group = null;
        $line_number = 1;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (substr($line, 0, 2) == '//') continue; // Discard comments.
            if (preg_match('/\[([[:alnum:]]+)\]/', $line, $out)) $in_group = strtolower($out[1]);
            else {
                list($key, $value) = explode('=', $line, 2);
                if (empty($key)) {
                    trigger_error("Nonsensical service configuration directive: $line ($service line $line_number)", E_USER_WARNING);
                    continue;
                }
                if ($in_group == 'service') {
                    if ($key == 'host') $this->Host = trim($value);
                    elseif ($key == 'max-chunk-size') $this->MaxChunkSize = intval(trim($value));
                }
                if (!isset($this->Config[$in_group]) or !is_array($this->Config[$in_group])) $this->Config[$in_group] = array();
                $this->Config[$in_group][$key] = trim($value);

                if (isset($required[$in_group]) and isset($required[$in_group][$key])) $required[$in_group][$key] = true;
            }
            $line_number++;
        }

        // Make sure this service has defined the base set of options.
        $serviceDefined = true;
        foreach ($required as $group => $req) foreach ($req as $key => $value) $serviceDefined = $serviceDefined and $value;

        if (!$serviceDefined) trigger_error("Service file $service does not define a valid service.", E_USER_WARNING);
        else trigger_error("Successfully parsed a valid service definition from $service", E_USER_NOTICE);
        return $serviceDefined;
    }

    function getConfigOption ($group, $key) {
        if (!is_array($this->Config)) {
            trigger_error("No service configuration defined.", E_USER_WARNING);
            return null;
        }

        if (!isset($this->Config[$group])) {
            trigger_error("No configuration group with the name $group is defined.", E_USER_WARNING);
            return null;
        }

        if (!isset($this->Config[$group][$key])) {
            //trigger_error("No configuration option with the name $key in group $group.", E_USER_NOTICE);
            return null;
        }

        return $this->Config[$group][$key];
    }

    function store ($chunk) {
    	trigger_error("Attempting to use service " . $this->Name . " to store chunk", E_USER_NOTICE);

        // Encode
        $encodeMethod = $this->getConfigOption('service', 'encode');
        if (!$encodeMethod) $encodeMethod = 'none';
        switch ($encodeMethod) {
            case 'base64': $chunk = FurlEncoder_Base64::encode($chunk); break;
            case 'none': break;
            default:
                trigger_error("No encoder for method $encodeMethod.", E_USER_ERROR);
                return false;
                break;
        }

        // Wrap
        $wrapMethod = $this->getConfigOption('service', 'wrapper');
        if (!$wrapMethod) $wrapMethod = 'none';
        switch ($wrapMethod) {
            case 'valid-url': $chunk = FurlWrapper_ValidURL::wrap($chunk); break;
            case 'none': break;
            default:
                trigger_error("No wrapper for method $wrapMethod.", E_USER_ERROR);
                return false;
                break;
        }

        if (!empty($this->MaxChunkSize) and strlen($chunk) > $this->MaxChunkSize) {
            trigger_error("Chunk is " . strlen($chunk) . " characters; too large for service (max size {$this->MaxChunkSize}).", E_USER_WARNING);
            return 'TOOLARGE';
        }

        // Submit
        $host = $this->Host;
        $port = 80;

        $endpoint = $this->getConfigOption('store', 'endpoint');
        $urlparam = $this->getConfigOption('store', 'urlparam');
        $otherparams = $this->getConfigOption('store', 'otherparams');

        $all_params = '';
        if (!empty($otherparams)) {
            $otherparams = preg_split('/(?<!\\\),/', $otherparams);
            foreach ($otherparams as $op) {
                list($param, $value) = explode(':', $op);
                if (substr($value, 0, 1) == '#') $value = $this->smartParam($value);
                $all_params[] = urlencode($param) . '=' . urlencode($value);
            }
        }
        $all_params[] = urlencode($urlparam) . '=' . urlencode($chunk);
        $querystring = implode('&', $all_params);

        $method = $this->getConfigOption('store', 'method');
        if ($method != 'get' and $method != 'post') $method = 'get';
        $response = FurlHTTP::$method("$endpoint?$querystring", $host, $port);

        // Report
        if (!$response) {
            trigger_error("Failed to store data chunk on $host", E_USER_WARNING);
            return false;
        }

        list($headers, $body) = explode("\r\n\r\n", $response, 2);

        if (empty($headers) or empty($body)) {
            trigger_error("Empty headers or body:\n$headers\n\n$body\n\n", E_USER_WARNING);
            return 'BADSERVICE';
        }

        if (strpos($headers, '200 OK') === false) {
            trigger_error("Error storing data chunk on $host. Response:\n$response", E_USER_WARNING);
            return 'BADSERVICE';
        }

        $url = '';
        $return_info = $this->getConfigOption('store', 'response');
        switch ($return_info) {
            case 'scrape':
            	$scraper = $this->getConfigOption('store', 'scraper');
                preg_match_all($scraper, $body, $match);
                $url = $match[1][0];
                break;
            case 'entire-response-body':
            default:
                $url = trim($body);
                break;
        }

        // Make sure this looks like an actual valid URL.
        if (filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED)) return $url;
        else {
            trigger_error("Response from $host does not appear to be a valid URL.", E_USER_WARNING);
            return 'BADSERVICE';
        }
    }

    function fetch ($url) {
        // Submit request
        $host = $this->Host;
        $port = 80;

        $uri = str_replace("http://$host", '', $url);
        $response = FurlHTTP::get("$uri", $host, $port);

        // Report
        if (!$response) {
            trigger_error("Failed to fetch data chunk from $host", E_USER_WARNING);
            return false;
        }

        list($headers, $body) = explode("\r\n\r\n", $response, 2);

        $header_data = explode("\r\n", $headers);
        $headers = array();
        foreach ($header_data as $header) {
            if (strpos($header, ': ') === false) continue;
            list($h, $v) = explode(': ', $header);
            $headers[strtolower($h)] = $v;
        }

        $return_info = $this->getConfigOption('fetch', 'response');
        $data = null;
        switch ($return_info) {
            case 'entire-response-body':
                $data = trim($body);
                break;
            case 'scrape':
            	$scraper = $this->getConfigOption('fetch', 'scraper');
                preg_match_all($scraper, $body, $match);
                $data = $match[1][0];
                break;
            case 'location-header':
            default:
                $data = isset($headers['location']) ? $headers['location'] : false;
                break;
        }

        if (!$data) {
            trigger_error("Failed to find data chunk in response from $host", E_USER_WARNING);
            return false;
        }

        // Unwrap
        $wrapMethod = $this->getConfigOption('service', 'wrapper');
        if (!$wrapMethod) $wrapMethod = 'none';
        switch ($wrapMethod) {
            case 'valid-url': $data = FurlWrapper_ValidURL::unwrap($data); break;
            case 'none': break;
            default:
                trigger_error("No wrapper for method $wrapMethod.", E_USER_ERROR);
                return false;
                break;
        }

        // Decode
        $decodeMethod = $this->getConfigOption('service', 'encode');
        if (!$decodeMethod) $decodeMethod = 'none';
        switch ($decodeMethod) {
            case 'base64': $data = FurlEncoder_Base64::decode($data); break;
            case 'none': break;
            default:
                trigger_error("No encoder for method $encodeMethod.", E_USER_ERROR);
                return false;
                break;
        }

        return $data;
    }

    function smartParam ($value) {
    	$value = str_replace('\\', '', ltrim($value, '#'));
    	list($command, $params) = explode('/', $value);

    	$return_value = null;
    	switch ($command) {
    	    case 'random':
    	    case 'rand':
    	    case 'rnd':
    	        list($min, $max) = explode(',', $params);
    	        $return_value = rand($min, $max);
    	        break;
    	}

    	return $return_value;
    }

}

// A furl encoder takes a chunk of raw data and encodes it to fit the storage parameters of a furl service.
class FurlEncoder {

    function encode ($data) {
        return $data;
    }

    function decode ($data) {
        return $data;
    }

}

class FurlEncoder_Base64 extends FurlEncoder {

    function encode ($data) {
        return base64_encode($data);
    }

    function decode ($data) {
        return base64_decode($data);
    }

}

// A furl wrapper takes an encoded data chunk and wraps it up so that it appears to be something else.
class FurlWrapper {

    function wrap ($data) {
        return $data;
    }

    function unwrap ($data) {
        return $data;
    }

}

class FurlWrapper_ValidURL {

    function wrap ($data) {
        $divider = FurlWrapper_ValidURL::divider($data);
        return FurlWrapper_ValidURL::randomValidURL() . $divider . urlencode($data) . $divider;
    }

    function randomValidURL () {

        switch (rand(1, 5)) {
            case 1:
                return 'http://www.google.com/search?&q=' . urlencode(FurlWrapper_ValidURL::fakeSearch()) . '&hl=en&utm=';
                break;
            case 2:
                return 'http://search.yahoo.com/search?p=' . urlencode(FurlWrapper_ValidURL::fakeSearch()) . '&toggle=1&cop=mss&ei=UTF-8&kl=';
                break;
            case 3:
                return 'http://www.bing.com/search?q=' . urlencode(FurlWrapper_ValidURL::fakeSearch()) . '&go=&form=QBLH&qs=n&rs=';
                break;
            case 4:
                return 'http://search.twitter.com/search?q=' . urlencode(FurlWrapper_ValidURL::fakeSearch()) . '&_qg=';
                break;
            case 5:
                return 'http://www.flickr.com/search/?q=' . urlencode(FurlWrapper_ValidURL::fakeSearch()) . '&w=all&lic=';
                break;
        }

    }

    function divider ($data) {
        $potential = substr($data, 0, 5);
        while (strpos($data, $potential) !== false) {
            $potential = '';
            for ($i = 0; $i <= 5; $i++) rand(0, 10) % 2 ? $potential .= chr(rand(71, 90)) : $potential .= chr(rand(103, 122));
        }
        return $potential;
    }

    function fakeSearch () {
        for ($fakeSearch = '', $i = 0, $numTerms = rand(1, 3); $i < $numTerms; $i++) $fakeSearch .= FurlWrapper_ValidURL::fakeWord() . ' ';
        return trim($fakeSearch);
    }

    function fakeWord () {
        $vowels = array('a', 'e', 'i', 'o', 'u');
        $consonants = array('b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm', 'n', 'p', 'qu', 'r', 's', 't', 'v', 'w', 'x', 'y', 'z', 'th', 'sh', 'ch');

        for ($fakeWord = '', $i = 0, $numSyllables = rand(2, 4); $i < $numSyllables; $i++) {
            switch (rand(1, 3)) {
                case 1: $fakeWord .= $vowels[rand(0, count($vowels) - 1)]; break;
                case 2: $fakeWord .= $consonants[rand(0, count($consonants) - 1)] . $vowels[rand(0, count($vowels) - 1)]; break;
                case 3: $fakeWord .= $consonants[rand(0, count($consonants) - 1)] . $vowels[rand(0, count($vowels) - 1)] . $consonants[rand(0, count($consonants) - 1)]; break;
            }
        }

        return $fakeWord;
    }

    function unwrap ($data) {
        $divider = substr($data, -5);
        list ($fake_url, $real_data, $junk) = explode($divider, $data);
	while (strpos($real_data, '%') !== false) $real_data = urldecode($real_data);
        return $real_data;
    }

}

class FurlHTTP {

    function get ($uri, $host = null, $port = 80) {
        trigger_error("Getting $uri from $host:$port", E_USER_NOTICE);

        $fp = false;
        $attempts = 5;
        while (!$fp and $attempts) {
            $fp = fsockopen($host, $port, $errno, $errstr, 3);
            if (!$fp or $errno) {
                trigger_error("Failed to connect to server $host on port $port. Error number $errno, message $errstr", E_USER_WARNING);
                $attempts--;
            }
        }
        if (!$fp) {
            trigger_error("Failed to open socket after 5 attempts", E_USER_ERROR);
            return 'BADSERVICE';
        }

        fputs($fp, "GET $uri HTTP/1.0\r\nHost: $host\r\nConnection: close\r\n\r\n");

        $ret = '';
        while (!feof($fp)) $ret .= fgets($fp, 1024);
        fclose($fp);

        if (!$ret) {
            trigger_error("No response. Error number $errno, message $errstr.", E_USER_WARNING);
            return 'BADSERVICE';
        }

        return $ret;
    }

    function post ($uri, $host = null, $port = 80) {
        trigger_error("Posting $uri to $host:$port", E_USER_NOTICE);

        $fp = false;
        $attempts = 5;
        while (!$fp and $attempts) {
            $fp = fsockopen($host, $port, $errno, $errstr, 3);
            if (!$fp or $errno) {
                trigger_error("Failed to connect to server $host on port $port. Error number $errno, message $errstr", E_USER_WARNING);
                $attempts--;
            }
        }
        if (!$fp) {
            trigger_error("Failed to open socket after 5 attempts", E_USER_ERROR);
            return 'BADSERVICE';
        }

        list($uri, $content) = explode('?', $uri);
        $length = strlen($content);

        fputs($fp, "POST $uri HTTP/1.0\r\nHost: $host\r\nContent-type: application/x-www-form-urlencoded\r\nContent-length: $length\r\nConnection: close\r\n\r\n");
        fputs($fp, $content);

        $ret = '';
        while (!feof($fp)) $ret .= fgets($fp, 1024);
        fclose($fp);

        if (!$ret) {
            trigger_error("No response. Error number $errno, message $errstr.", E_USER_WARNING);
            return 'BADSERVICE';
        }
        return $ret;
    }

}

?>
