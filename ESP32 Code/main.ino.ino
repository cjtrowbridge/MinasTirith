#include <lwip/apps/sntp.h>
#include <WiFi.h>
#include <BLEDevice.h>
#include <ArduinoOTA.h>
#include <ESPAsyncWebSrv.h>
#include <AsyncTCP.h>
#include <HTTPClient.h>
#include "passwords.h"

/*

  Note that you must change the ESP32 partition scheme 
  to minimal spiffs in order to have enough space for this program.

*/

// Web server instance on port 80
AsyncWebServer server(80);

// Constants
const int MAX_DEVICES = 50;  // Adjust based on your requirements
const int MAX_TIMESERIES_LENGTH = 1;

#define SERVICE_UUID        "4fafc201-1fb5-459e-8fcc-c5c9c331914b"
#define CHARACTERISTIC_UUID "beb5483e-36e1-4688-b7f5-ea07361b26a8"
BLEServer* pServer = NULL;
BLECharacteristic* pCharacteristic = NULL;
bool deviceConnected = false;
bool oldDeviceConnected = false;

// Data structures
struct BTData {
  time_t timestamp;  // UNIX timestamp
  int16_t rssi;
  float distance;
};

struct BLEDeviceData {
  char address[18] = {0};
  BTData data[MAX_TIMESERIES_LENGTH];
  int dataCount = 0;
  String manufacturer = "";
};

BLEDeviceData devices[MAX_DEVICES];
int deviceCount = 0;

// Function to initialize the SNTP for time synchronization
void initializeSntp() {
    Serial.println("Synchronizing with NTP server...");
    sntp_setoperatingmode(SNTP_OPMODE_POLL);
    sntp_setservername(0, "pool.ntp.org");
    sntp_init();
}

// Function to calculate distance using RSSI
float calculateDistance(int rssi) {
  int txPower = -59;
  if (rssi == 0) {
    return -1.0f;
  }
  float ratio = rssi * 1.0f / txPower;
  if (ratio < 1.0f) {
    return pow(ratio, 10);
  } else {
    float distance = 0.89976f * pow(ratio, 7.7095f) + 0.111f;
    return distance;
  }
}

// Find a Bluetooth device by its MAC address
int findDeviceByAddress(const char* address) {
  for (int i = 0; i < deviceCount; i++) {
    if (strcmp(devices[i].address, address) == 0) {
      return i;
    }
  }
  return -1;  // Not found
}

// Get manufacturer name from an online database using the first 8 characters (OUI) of the MAC address
String getManufacturerFromOUI(const char* address) {
    String blankString = "";
    return blankString;

    HTTPClient http;
    String oui = String(address).substring(0, 8);
    String url = "http://server/api/oui/?oui=" + oui;

    http.begin(url.c_str());
    int httpResponseCode = http.GET();

    String manufacturer = "";

    if (httpResponseCode == 200) {
        manufacturer = http.getString();
    }

    http.end();

    // Debug outputs
    Serial.println("OUI: " + oui);
    Serial.println("Request URL: " + url);
    Serial.println("HTTP Response Code: " + String(httpResponseCode));
    Serial.println("Manufacturer: " + manufacturer);

    return manufacturer;
}

// Callbacks for advertised BLE devices during scanning
class MyAdvertisedDeviceCallbacks: public BLEAdvertisedDeviceCallbacks {
  void onResult(BLEAdvertisedDevice advertisedDevice) {
    char address[18];
    strncpy(address, advertisedDevice.getAddress().toString().c_str(), sizeof(address) - 1);
    address[17] = '\0';
    int rssi = advertisedDevice.getRSSI();
    float distance = calculateDistance(rssi);

    Serial.println("Bluetooth Device: " + String(address) + " " + String(rssi) + "db " + String(distance) + "m");


    BTData newData = { time(nullptr), rssi, distance };
    int index = findDeviceByAddress(address);
    if (index == -1) {
      if (deviceCount < MAX_DEVICES) {
        strncpy(devices[deviceCount].address, address, 18);
        devices[deviceCount].data[devices[deviceCount].dataCount++] = newData;
        devices[deviceCount].manufacturer = getManufacturerFromOUI(address);
        deviceCount++;
      }
    } else {
      if (devices[index].dataCount < MAX_TIMESERIES_LENGTH) {
        devices[index].data[devices[index].dataCount++] = newData;
      } else {
        memmove(&devices[index].data[0], &devices[index].data[1], sizeof(BTData) * (MAX_TIMESERIES_LENGTH - 1));
        devices[index].data[MAX_TIMESERIES_LENGTH - 1] = newData;
      }
    }
  }
};

// Function to initiate scanning for BLE devices
void scanForBTDevices() {
  BLEScan* pBLEScan = BLEDevice::getScan();
  pBLEScan->setAdvertisedDeviceCallbacks(new MyAdvertisedDeviceCallbacks());
  pBLEScan->setActiveScan(true);
  pBLEScan->start(10, false);
  pBLEScan->clearResults();
}

// Function to handle incoming web requests
void handleJson(AsyncWebServerRequest *request) {
    AsyncResponseStream *response = request->beginResponseStream("application/json");
    time_t currentTime = time(nullptr);
    long uptime = millis()/1000;
    uint32_t freeHeap = ESP.getFreeHeap();
    uint32_t totalHeap = ESP.getHeapSize();
    uint32_t freeSketchSpace = ESP.getFreeSketchSpace();
    uint32_t sketchSize = ESP.getSketchSize();

    response->print("{\n");
    response->print("  \"meta\": {\n");
    response->printf("    \"timestamp\": %lld,\n", (long long)currentTime);
    response->printf("    \"uptime\": %lld,\n", (long long)uptime);
    response->printf("    \"ipAddress\": \"%s\",\n", WiFi.localIP().toString().c_str());
    response->printf("    \"wifiMac\": \"%s\",\n", WiFi.macAddress().c_str());
    response->printf("    \"bluetoothMac\": \"%s\",\n", BLEDevice::getAddress().toString().c_str());
    response->print("    \"peers\": [],\n");
    response->print("    \"memoryStats\": {\n");
    response->printf("      \"heapFree\": %u,\n", freeHeap);
    response->printf("      \"heapTotal\": %u,\n", totalHeap);
    response->printf("      \"heapPercentFree\": %.2f,\n", (float)freeHeap / totalHeap * 100);
    response->printf("      \"sketchFree\": %u,\n", freeSketchSpace);
    response->printf("      \"sketchUsed\": %u,\n", sketchSize);
    response->printf("      \"sketchPercentFree\": %.2f\n", (float)freeSketchSpace / (freeSketchSpace + sketchSize) * 100);
    response->print("    }\n");
    response->print("  },\n");
    response->print("  \"beacons\": {\n");
    
    for (int i = 0; i < deviceCount; i++) {
        response->printf("    \"%s\": {\n", devices[i].address);
        response->print("      \"meta\": {\n");
        response->printf("        \"manufacturer\": \"%s\"\n", devices[i].manufacturer.c_str());
        response->print("      },\n");
        response->print("      \"timeseries\": [\n");

        for (int j = 0; j < devices[i].dataCount; j++) {
            response->print("        {\n");
            response->printf("          \"timestamp\": %lld,\n", (long long)devices[i].data[j].timestamp);
            response->printf("          \"rssi\": %d,\n", devices[i].data[j].rssi);
            response->printf("          \"distance\": %.4f\n", devices[i].data[j].distance);
            if (j == devices[i].dataCount - 1) {
                response->print("        }\n");
            } else {
                response->print("        },\n");
            }
        }

        if (i == deviceCount - 1) {
            response->print("      ]\n    }\n");
        } else {
            response->print("      ]\n    },\n");
        }
    }
    response->print("  }\n");
    response->print("}\n");

    request->send(response);
}

// OTA setup function
void setupOTA() {
    ArduinoOTA.setHostname("ESP-32");
    ArduinoOTA.setPassword("osgiliath");
    ArduinoOTA.begin();
}

// Function to seek for peers
unsigned long httpTimeout = 500;
void seekPeers() {
    Serial.println("Seeking peers...");
    HTTPClient http;

    // Set the timeout for the HTTP request
    http.setTimeout(httpTimeout);

    // Extract the subnet from the device's IP
    String subnet = WiFi.localIP().toString().substring(0, WiFi.localIP().toString().lastIndexOf('.'));

    // Scan IPs in the subnet
    for (int i = 1; i <= 254; i++) {  // Assuming a /24 subnet mask
        String targetIP = subnet + "." + String(i);

        // Check if target IP is not the device's IP
        if (targetIP != WiFi.localIP().toString()) {
            String url = "http://" + targetIP + "/";
            Serial.println("Checking: " + targetIP);

            http.begin(url.c_str());
            int httpResponseCode = http.GET();

            if (httpResponseCode == 200) {
                String payload = http.getString();

                // Check if the payload contains both "meta" and "beacons" keys
                if (payload.indexOf("\"meta\"") != -1 && payload.indexOf("\"beacons\"") != -1) {
                    Serial.println("Found peer with meta and beacons at: " + targetIP);
                }
            }

            http.end();
        }
    }
    Serial.println("Done seeking peers.");
}


// Arduino setup function
void setup() {
    Serial.begin(115200);
    WiFi.begin(ssid, password);
    while (WiFi.status() != WL_CONNECTED) {
      delay(1000);
      Serial.println("Connecting to WiFi...");
    }
    Serial.println("Connected to WiFi");

    initializeSntp();
    
    Serial.println("Setting up bluetooth...");
    BLEDevice::init("");

    Serial.println("Setting up web server...");
    server.on("/data.json", HTTP_GET, handleJson);
    server.begin();
    
    delay(2000);

    Serial.println("Setting up OTA server...");
    setupOTA();

    //Set up BLE server so the devices can see each other when they scan for bluetooth devices
    Serial.println("Setting up BLE server...");

    // Create the BLE Device
    BLEDevice::init("MinasTirith"); // you can use any name here

    // Create the BLE Server
    pServer = BLEDevice::createServer();

    // Create the BLE Service
    BLEService *pService = pServer->createService(SERVICE_UUID);

    // Create a BLE Characteristic
    pCharacteristic = pService->createCharacteristic(
                        CHARACTERISTIC_UUID,
                        BLECharacteristic::PROPERTY_READ   |
                        BLECharacteristic::PROPERTY_WRITE  |
                        BLECharacteristic::PROPERTY_NOTIFY |
                        BLECharacteristic::PROPERTY_INDICATE
                      );

    // Start the service
    pService->start();

    // Start advertising
    pServer->getAdvertising()->start();
    Serial.println("BLE server is advertising now...");
}

// Main loop
const unsigned long SCAN_INTERVAL = 5000; // 5 seconds in milliseconds
unsigned long lastScanTime = 0;
unsigned long lastPeerSeekTime = 0;
const unsigned long PEER_SEEK_INTERVAL = 600000; // 10 minutes in milliseconds

void loop() {
    ArduinoOTA.handle();
    
    if (millis() - lastScanTime >= SCAN_INTERVAL) {
      scanForBTDevices();
      lastScanTime = millis();
    }

    // Only seek peers after the first 10 minutes and then every 10 minutes after
    if (millis() - lastPeerSeekTime >= PEER_SEEK_INTERVAL && millis() > PEER_SEEK_INTERVAL) {
        seekPeers();
        lastPeerSeekTime = millis();
    }
}

