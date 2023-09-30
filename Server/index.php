<?php

date_default_timezone_set('America/Los_Angeles');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db = new SQLite3('ips.db');
ensureTablesExist();

function ensureTablesExist() {
    global $db;
    $db->exec('CREATE TABLE IF NOT EXISTS ip_addresses (ip STRING PRIMARY KEY, last_seen INTEGER)');
    $db->exec('CREATE TABLE IF NOT EXISTS scan_runtimes (id INTEGER PRIMARY KEY, start_time INTEGER, end_time INTEGER, duration INTEGER)');
    $sql = "CREATE TABLE IF NOT EXISTS Scanned_Devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                iot_bluetooth_mac TEXT NOT NULL,
                seen_bluetooth_mac TEXT NOT NULL,
                rssi INTEGER,
                estimated_distance REAL,
                seen_time INTEGER,
                UNIQUE(iot_bluetooth_mac, seen_bluetooth_mac, seen_time)
            )";
    $db->exec($sql);
}

function computeAvgDistance(){
    global $db;

    $data = [];

    $thirtyMinutesAgo = time()-60*3;

    $sql = "SELECT iot_bluetooth_mac, seen_bluetooth_mac, AVG(rssi) as avg_rssi, AVG(estimated_distance) as avg_distance
            FROM Scanned_Devices
            WHERE seen_time > ".$thirtyMinutesAgo."
            GROUP BY iot_bluetooth_mac, seen_bluetooth_mac";

    $results = $db->query($sql);
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }

    return $data;
}

function computeAvgBeaconDistance(){
    global $db;

    $data = [];

    $oneDayAgo = time()-60*60*24;

    $sql = "SELECT iot_bluetooth_mac, seen_bluetooth_mac, AVG(rssi) as avg_rssi, AVG(estimated_distance) as avg_distance
            FROM Scanned_Devices
            WHERE seen_time > ".$oneDayAgo."
            AND seen_bluetooth_mac IN (SELECT DISTINCT iot_bluetooth_mac FROM Scanned_Devices)
            GROUP BY iot_bluetooth_mac, seen_bluetooth_mac";

    $results = $db->query($sql);
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }

    return $data;
}

function fetchDataFromHosts() {
    global $db;

    // Path to the CSV file
    $csvFile = 'beacons.csv';

    // Open the file for appending
    $handle = fopen($csvFile, 'a');

    // If the file is empty, write the headers
    if (filesize($csvFile) === 0) {
        fputcsv($handle, ['IoT Bluetooth MAC', 'Seen Bluetooth MAC', 'RSSI', 'Estimated Distance', 'Seen Time']);
    }

    // Fetch known hosts from the database
    $knownHosts = [];
    $results = $db->query('SELECT ip FROM ip_addresses');
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $knownHosts[] = $row['ip'];
    }

    foreach ($knownHosts as $host) {
        $url = "http://$host/data.json";
        $json = file_get_contents($url);
        $data = json_decode($json, true);

        if ($data && isset($data['beacons']) && isset($data['meta']['bluetoothMac'])) {
            $iot_bluetooth_mac = $data['meta']['bluetoothMac'];

            foreach ($data['beacons'] as $seen_bluetooth_mac => $beaconData) {
                foreach ($beaconData['timeseries'] as $entry) {
                    $seen_time = $entry['timestamp'];
                    $rssi = $entry['rssi'];
                    $estimated_distance = $entry['distance'];

                    // Insert into the database
                    $stmt = $db->prepare("INSERT OR IGNORE INTO Scanned_Devices (iot_bluetooth_mac, seen_bluetooth_mac, rssi, estimated_distance, seen_time) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bindValue(1, $iot_bluetooth_mac);
                    $stmt->bindValue(2, $seen_bluetooth_mac);
                    $stmt->bindValue(3, $rssi);
                    $stmt->bindValue(4, $estimated_distance);
                    $stmt->bindValue(5, $seen_time);
                    $stmt->execute();

                    // Append to the CSV file
                    fputcsv($handle, [$iot_bluetooth_mac, $seen_bluetooth_mac, $rssi, $estimated_distance, $seen_time]);
                }
            }
        }
    }

    // Close the CSV file handle
    fclose($handle);
}

function fetchLastScannedDevices() {
    global $db;
    $data = [];
    try {
        $results = $db->query('SELECT * FROM Scanned_Devices ORDER BY id DESC LIMIT 1000');
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }
    } catch (Exception $e) {
        // Handle the error or log it.
    }
    return $data;
}

function scanNetwork() {
    global $db;

    $startTime = time();

    $baseUrl = "http://%s/data.json";
    for ($i = 1; $i <= 254; $i++) {
        $ip = "192.168.1." . $i;
        $url = sprintf($baseUrl, $ip);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $jsonData = json_decode($response, true);
            if (isset($jsonData['meta']) && isset($jsonData['beacons'])) {
                $stmt = $db->prepare('INSERT OR REPLACE INTO ip_addresses (ip, last_seen) VALUES (:ip, :last_seen)');
                $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
                $stmt->bindValue(':last_seen', time(), SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }

    $endTime = time();
    $duration = $endTime - $startTime;

    $stmt = $db->prepare('INSERT INTO scan_runtimes (start_time, end_time, duration) VALUES (:start, :end, :duration)');
    $stmt->bindValue(':start', $startTime, SQLITE3_INTEGER);
    $stmt->bindValue(':end', $endTime, SQLITE3_INTEGER);
    $stmt->bindValue(':duration', $duration, SQLITE3_INTEGER);
    $stmt->execute();

    return ['status' => 'success'];
}

function fetchExistingData() {
    global $db;
    $data = [];
    $results = $db->query('SELECT * FROM ip_addresses');
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }
    return $data;
}

function fetchScanRuntimes() {
    global $db;
    $data = [];
    try {
        $results = $db->query('SELECT * FROM scan_runtimes ORDER BY id DESC LIMIT 10');
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }
    } catch (Exception $e) {
        // Handle the error or log it.
        // In this case, just continue and return an empty array.
    }
    return $data;
}

/*

  These endpoints are called by cron jobs on the server. 
  /?action=scan is called every ten minutes.
  /?action=fetchLatestData is called every ten seconds.

*/
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] == 'scan') {
        header('Content-Type: application/json');
        echo json_encode(scanNetwork());
        exit;
    } elseif ($_GET['action'] == 'fetchLatestData') {
        fetchDataFromHosts();
        header('Content-Type: application/json');
        echo json_encode(fetchLastScannedDevices());
        exit;
    }elseif ($_GET['action'] == 'data') {
      $Data = array(
        'ipData'                => fetchExistingData(),
        'runtimeData'           => fetchScanRuntimes(),
        'avgDistanceData'       => computeAvgDistance(),
        'beaconAvgDistanceData' => computeAvgBeaconDistance()
      );
      echo json_encode($Data);
      exit;
    }
} else {
    $ipData = fetchExistingData();
    $runtimeData = fetchScanRuntimes();
    $scannedDevicesData = fetchLastScannedDevices();
    $avgDistanceData = computeAvgDistance();
    $beaconAvgDistanceData = computeAvgBeaconDistance();

?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Network Scanner</title>
        <!-- Bootstrap CSS -->
        <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
        <!-- jQuery -->
        <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    </head>
    <body>
    <div class="container mt-5">
        <h1 class="mb-4">Network Scanner</h1>
        <!--div id="loadingMessage" class="alert alert-info">Seeking peers, please wait...</div-->
        
        <h2 class="mt-5 mb-4">24-Hour Average Distance(m) Between Beacons</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>IoT Bluetooth MAC</th>
                    <th>Seen Bluetooth MAC</th>
                    <th>Average RSSI</th>
                    <th>Average Estimated Distance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($beaconAvgDistanceData as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['iot_bluetooth_mac']) ?></td>
                        <td><?= htmlspecialchars($row['seen_bluetooth_mac']) ?></td>
                        <td><?= number_format($row['avg_rssi'], 2) ?></td>
                        <td><?= number_format($row['avg_distance'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h2 class="mt-5 mb-4">Recent Average Distance(m) Between Devices</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>IoT Bluetooth MAC</th>
                    <th>Seen Bluetooth MAC</th>
                    <th>Average RSSI</th>
                    <th>Average Estimated Distance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($avgDistanceData as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['iot_bluetooth_mac']) ?></td>
                        <td><?= htmlspecialchars($row['seen_bluetooth_mac']) ?></td>
                        <td><?= number_format($row['avg_rssi'], 2) ?></td>
                        <td><?= number_format($row['avg_distance'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="mt-5 mb-4">Known Peers</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Last Seen</th>
                </tr>
            </thead>
            <tbody id="ipList">
                <?php foreach ($ipData as $row): ?>
                    <tr>
                        <td><a href="http://<?= htmlspecialchars($row['ip']) ?>/data.json" target="_blank"><?= htmlspecialchars($row['ip']) ?></a></td>
                        <td><?= date("Y-m-d H:i:s", $row['last_seen']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="mt-5 mb-4">Scan Runtimes</h2>
        <table class="table">
          <thead>
              <tr>
                  <th>Start Time</th>
                  <th>End Time</th>
                  <th>Duration (seconds)</th>
              </tr>
          </thead>
          <tbody>
              <?php foreach ($runtimeData as $row): ?>
                  <tr>
                      <td><?= date("Y-m-d H:i:s", $row['start_time']) ?></td>
                      <td><?= date("Y-m-d H:i:s", $row['end_time']) ?></td>
                      <td><?= $row['duration'] ?></td>
                  </tr>
              <?php endforeach; ?>
          </tbody>
        </table>

        <h2 class="mt-5 mb-4">Last 1000 Scanned Data Points</h2>
        <table class="table" id="beacons">
            <thead>
                <tr>
                    <th>IoT Bluetooth MAC</th>
                    <th>Seen Bluetooth MAC</th>
                    <th>RSSI</th>
                    <th>Estimated Distance</th>
                    <th>Seen Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scannedDevicesData as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['iot_bluetooth_mac']) ?></td>
                        <td><?= htmlspecialchars($row['seen_bluetooth_mac']) ?></td>
                        <td><?= $row['rssi'] ?></td>
                        <td><?= $row['estimated_distance'] ?></td>
                        <td><?= date("Y-m-d H:i:s", $row['seen_time']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>

    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    </body>
    </html>
<?php

}
$db->close();
