
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <ESP32Servo.h>
#include <Wire.h>
#include <RTClib.h>

// ==================================================
// 1. WIFI SETTINGS
// ==================================================

const char* WIFI_SSID = "PLDTHOMEFIBRa11b0";
const char* WIFI_PASSWORD = "PLDTWIFIcp47z";

const char* SERVER_IP = "192.168.1.20";

// ==================================================
// 2. PHP BACKEND URLS
// ==================================================

const char* MANUAL_CHECK_URL =
  "http://192.168.1.20/purrfeedn/backend/esp32_manual.php?action=check";

const char* MANUAL_CLAIM_BASE_URL =
  "http://192.168.1.20/purrfeedn/backend/esp32_manual.php?action=claim&id=";

const char* MANUAL_RESULT_URL =
  "http://192.168.1.20/purrfeedn/backend/manual_dispensed.php";

const char* SCHEDULE_LIST_URL =
  "http://192.168.1.20/purrfeedn/backend/esp32_sched.php";

const char* SCHEDULE_CLAIM_URL =
  "http://192.168.1.20/purrfeedn/backend/claim_sched.php";

const char* SCHEDULE_RESULT_URL =
  "http://192.168.1.20/purrfeedn/backend/sched_dispensed.php";

// ==================================================
// 3. HARDWARE
// ==================================================

const int SERVO_PIN = 18;  // D18
const int LED_PIN = 2;     // D2

// DS3231 I2C pins.
// Standard ESP32-WROOM-32 defaults: SDA = GPIO 21,
// SCL = GPIO 22. Change if your wiring differs.

// ==================================================
// 4. SERVO SETTINGS
// ==================================================

const int SERVO_CLOSED_ANGLE = 0;
const int SERVO_OPEN_ANGLE = 90;

// Configure the actual servo opening duration HERE.
// These values are used instead of the database duration.
const float MANUAL_DISPENSE_SECONDS = 1.0;
const float SCHEDULE_DISPENSE_SECONDS = 1.0;

// ==================================================
// 5. TIMING
// ==================================================

const unsigned long WIFI_RETRY_MS = 5000;
const unsigned long COMMAND_POLL_MS = 10000;
const unsigned long RESULT_RETRY_MS = 5000;

// Do not start a schedule more than 2 minutes late.
// The PHP endpoint also enforces its own time checks.
const uint32_t SCHEDULE_GRACE_SECONDS = 120;

// ==================================================
// 6. GLOBAL VARIABLES
// ==================================================

Servo feederServo;
RTC_DS3231 rtc;

bool servoReady = false;
bool rtcReady = false;

bool resultPending = false;
bool pendingIsSchedule = false;

int pendingCommandId = 0;
String pendingResultStatus = "";
float pendingDispenseSeconds = 1.0;

unsigned long lastCommandPoll = 0;
unsigned long lastWifiRetry = 0;
unsigned long lastResultRetry = 0;

// ==================================================
// 7. SETUP
// ==================================================

void setup() {
  Serial.begin(115200);
  delay(1000);

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  Serial.println();
  Serial.println("================================");
  Serial.println(" PurrFeed ESP32 Feeder");
  Serial.println(" Manual + Scheduled Feeding");
  Serial.println("================================");

  // Initialize servo.
  feederServo.setPeriodHertz(50);
  feederServo.attach(SERVO_PIN, 500, 2400);
  servoReady = feederServo.attached();

  if (servoReady) {
    feederServo.write(SERVO_CLOSED_ANGLE);
    Serial.println("Servo initialized.");
  } else {
    Serial.println("ERROR: Servo is not attached.");
  }

  // Initialize RTC.
  Wire.begin(21, 22);

  if (!rtc.begin()) {
    Serial.println("ERROR: DS3231 not detected.");
    rtcReady = false;
  } else {
    rtcReady = true;  
    Serial.println("DS3231 detected.");

    if (rtc.lostPower()) {
      Serial.println("WARNING: RTC lost power.");
      Serial.println("Setting RTC to sketch compile time.");

      // Compile-time clock is used only when the RTC
      // reports lost power. Ensure the computer's
      // clock is correct before uploading.
      rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
    }

    printRTCTime();
  }

  connectWiFi();

  Serial.println("PurrFeed feeder is ready.");
}

// ==================================================
// 8. MAIN LOOP
// ==================================================

void loop() {
  maintainWiFi();

  if (WiFi.status() != WL_CONNECTED) {
    digitalWrite(LED_PIN, LOW);
    delay(100);
    return;
  }

  // Always retry an unsaved result before accepting
  // another command. This prevents overlapping cycles.
  if (resultPending) {
    if (millis() - lastResultRetry >= RESULT_RETRY_MS) {
      lastResultRetry = millis();
      sendFeedingResult();
    }

    delay(50);
    return;
  }

  if (millis() - lastCommandPoll >= COMMAND_POLL_MS) {
    lastCommandPoll = millis();

    // Manual requests have priority. If one starts
    // feeding or creates a pending result, stop here.
    checkForManualCommand();

    if (resultPending) {
      return;
    }

    // Do not run schedule checks if the RTC is invalid.
    if (rtcReady) {
      checkForScheduledFeeding();
    }
  }

  delay(20);
}

// ==================================================
// 9. WIFI
// ==================================================

void connectWiFi() {
  Serial.print("Connecting to Wi-Fi: ");
  Serial.println(WIFI_SSID);

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  unsigned long startAttempt = millis();

  while (WiFi.status() != WL_CONNECTED &&
         millis() - startAttempt < 20000) {
    delay(500);
    Serial.print(".");
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Wi-Fi connected!");
    Serial.print("ESP32 IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("Wi-Fi connection failed. Will retry.");
  }
}

void maintainWiFi() {
  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  if (millis() - lastWifiRetry >= WIFI_RETRY_MS) {
    lastWifiRetry = millis();

    Serial.println("Wi-Fi disconnected. Reconnecting...");
    WiFi.disconnect();
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  }
}

// ==================================================
// 10. RTC HELPERS
// ==================================================

void printRTCTime() {
  if (!rtcReady) {
    return;
  }

  DateTime now = rtc.now();

  Serial.printf(
    "RTC time: %04d-%02d-%02d %02d:%02d:%02d\n",
    now.year(),
    now.month(),
    now.day(),
    now.hour(),
    now.minute(),
    now.second()
  );
}

// Parse "YYYY-MM-DD" and "HH:MM:SS" from PHP.
bool parseScheduleTime(
  const char* dateText,
  const char* timeText,
  DateTime &scheduledTime
) {
  if (dateText == nullptr || timeText == nullptr) {
    return false;
  }

  int year, month, day;
  int hour, minute, second;

  if (sscanf(dateText, "%d-%d-%d",
             &year, &month, &day) != 3) {
    return false;
  }

  if (sscanf(timeText, "%d:%d:%d",
             &hour, &minute, &second) != 3) {
    return false;
  }

  if (year < 2000 || month < 1 || month > 12 ||
      day < 1 || day > 31 || hour < 0 || hour > 23 ||
      minute < 0 || minute > 59 ||
      second < 0 || second > 59) {
    return false;
  }

  scheduledTime = DateTime(
    year, month, day, hour, minute, second
  );

  return true;
}

// ==================================================
// 11. CHECK MANUAL COMMAND
// ==================================================

void checkForManualCommand() {
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  HTTPClient http;

  Serial.println("Checking for manual feeding...");

  http.setConnectTimeout(5000);
  http.setTimeout(8000);

  if (!http.begin(MANUAL_CHECK_URL)) {
    Serial.println("Could not start manual check.");
    return;
  }

  int httpCode = http.GET();
  String response = http.getString();

  Serial.print("Manual check HTTP: ");
  Serial.println(httpCode);
  Serial.println(response);

  http.end();

  if (httpCode != HTTP_CODE_OK) {
    return;
  }

  JsonDocument doc;

  if (deserializeJson(doc, response)) {
    Serial.println("Manual JSON parsing failed.");
    return;
  }

  if (!doc["success"].as<bool>()) {
    return;
  }

  if (!doc["command_available"].as<bool>()) {
    return;
  }

  int commandId = doc["command_id"].as<int>();

  if (commandId <= 0) {
    Serial.println("Invalid manual command ID.");
    return;
  }

  Serial.print("Manual command found: ");
  Serial.println(commandId);

  claimManualCommand(commandId);
}

// ==================================================
// 12. CLAIM MANUAL COMMAND
// ==================================================

void claimManualCommand(int commandId) {
  HTTPClient http;

  String url =
    String(MANUAL_CLAIM_BASE_URL) + String(commandId);

  http.setConnectTimeout(5000);
  http.setTimeout(8000);

  if (!http.begin(url)) {
    Serial.println("Could not start manual claim.");
    return;
  }

  int httpCode = http.GET();
  String response = http.getString();

  Serial.print("Manual claim HTTP: ");
  Serial.println(httpCode);
  Serial.println(response);

  http.end();

  if (httpCode != HTTP_CODE_OK) {
    Serial.println("Manual claim failed; servo will not run.");
    return;
  }

  JsonDocument doc;

  if (deserializeJson(doc, response)) {
    Serial.println("Manual claim JSON parsing failed.");
    return;
  }

  int claimedId = doc["command_id"] | 0;
  String status = doc["status"] | "";

  if (!doc["success"].as<bool>() ||
      claimedId != commandId ||
      status != "Processing") {
    Serial.println("Manual claim was not confirmed.");
    return;
  }

  Serial.println("Manual command claimed.");

  pendingCommandId = commandId;
  pendingIsSchedule = false;
  pendingDispenseSeconds = MANUAL_DISPENSE_SECONDS;

  bool succeeded = dispenseFood(pendingDispenseSeconds);

  pendingResultStatus = succeeded ? "Completed" : "Failed";
  resultPending = true;
  lastResultRetry = millis();

  sendFeedingResult();
}

// ==================================================
// 13. CHECK SCHEDULES
// ==================================================

void checkForScheduledFeeding() {
  if (!rtcReady || WiFi.status() != WL_CONNECTED) {
    return;
  }

  HTTPClient http;

  Serial.println("Checking scheduled feedings...");

  http.setConnectTimeout(5000);
  http.setTimeout(8000);

  if (!http.begin(SCHEDULE_LIST_URL)) {
    Serial.println("Could not start schedule request.");
    return;
  }
  
  int httpCode = http.GET();
  String response = http.getString();

  Serial.print("Schedule list HTTP: ");
  Serial.println(httpCode);
  Serial.println(response);

  http.end();

  if (httpCode != HTTP_CODE_OK) {
    return;
  }

  JsonDocument doc;

  if (deserializeJson(doc, response)) {
    Serial.println("Schedule JSON parsing failed.");
    return;
  }

  if (!doc["success"].as<bool>()) {
    Serial.println("Schedule request was unsuccessful.");
    return;
  }

  JsonArray schedules = doc["schedules"].as<JsonArray>();

  DateTime now = rtc.now();
  uint32_t nowUnix = now.unixtime();

  for (JsonObject schedule : schedules) {
    int scheduleId = schedule["schedule_id"] | 0;
    const char* dateText = schedule["feeding_date"];
    const char* timeText = schedule["feeding_time"];
    const char* statusText = schedule["status"];

    if (scheduleId <= 0 ||
        dateText == nullptr ||
        timeText == nullptr ||
        statusText == nullptr) {
      continue;
    }

    if (String(statusText) != "Pending") {
      continue;
    }

    DateTime scheduledTime(2000, 1, 1, 0, 0, 0);

    if (!parseScheduleTime(dateText, timeText, scheduledTime)) {
      Serial.println("Invalid schedule date/time.");
      continue;
    }

    uint32_t scheduledUnix = scheduledTime.unixtime();

    // Not due yet.
    if (nowUnix < scheduledUnix) {
      continue;
    }

    // Too late to start this schedule.
    if ((nowUnix - scheduledUnix) > SCHEDULE_GRACE_SECONDS) {
      Serial.print("Schedule is overdue: ");
      Serial.println(scheduleId);
      continue;
    }

    Serial.print("Due schedule found: ");
    Serial.println(scheduleId);

    // Claim through PHP before operating the servo.
    claimScheduledFeeding(scheduleId);

    // Process at most one schedule per check.
    return;
  }
}

// ==================================================
// 14. CLAIM SCHEDULE
// ==================================================

void claimScheduledFeeding(int scheduleId) {
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  HTTPClient http;

  http.setConnectTimeout(5000);
  http.setTimeout(8000);

  if (!http.begin(SCHEDULE_CLAIM_URL)) {
    Serial.println("Could not start schedule claim.");
    return;
  }

  http.addHeader("Content-Type", "application/json");

  JsonDocument requestDoc;
  requestDoc["schedule_id"] = scheduleId;

  String requestBody;
  serializeJson(requestDoc, requestBody);

  Serial.print("Claim schedule body: ");
  Serial.println(requestBody);

  int httpCode = http.POST(requestBody);
  String response = http.getString();

  Serial.print("Schedule claim HTTP: ");
  Serial.println(httpCode);
  Serial.println(response);

  http.end();

  if (httpCode != HTTP_CODE_OK) {
    Serial.println("Schedule claim failed; servo will not run.");
    return;
  }

  JsonDocument doc;

  if (deserializeJson(doc, response)) {
    Serial.println("Schedule claim JSON parsing failed.");
    return;
  }

  int claimedId = doc["schedule_id"] | 0;
  String status = doc["status"] | "";

  if (!doc["success"].as<bool>() ||
      claimedId != scheduleId ||
      status != "Processing") {
    Serial.println("Schedule claim was not confirmed.");
    return;
  }

  Serial.println("Schedule claimed successfully.");

  // Use the duration configured in this ESP32 sketch.
  pendingCommandId = scheduleId;
  pendingIsSchedule = true;
  pendingDispenseSeconds = SCHEDULE_DISPENSE_SECONDS;

  bool succeeded = dispenseFood(pendingDispenseSeconds);

  pendingResultStatus = succeeded ? "Completed" : "Failed";
  resultPending = true;
  lastResultRetry = millis();

  sendFeedingResult();
}

// ==================================================
// 15. DISPENSE FOOD
// ==================================================

bool dispenseFood(float durationSeconds) {
  Serial.println("Starting food dispensing...");

  if (!servoReady || !feederServo.attached()) {
    Serial.println("ERROR: Servo is not attached.");
    digitalWrite(LED_PIN, LOW);
    return false;
  }

  if (durationSeconds <= 0 || durationSeconds > 30) {
    Serial.println("ERROR: Invalid dispensing duration.");
    return false;
  }

  unsigned long durationMs =
    (unsigned long)(durationSeconds * 1000.0f);

  digitalWrite(LED_PIN, HIGH);
  feederServo.write(SERVO_OPEN_ANGLE);

  delay(durationMs);

  feederServo.write(SERVO_CLOSED_ANGLE);
  digitalWrite(LED_PIN, LOW);

  Serial.println("Dispensing cycle finished.");

  return true;
}

// ==================================================
// 16. SEND FEEDING RESULT
// ==================================================

void sendFeedingResult() {
  if (!resultPending ||
      WiFi.status() != WL_CONNECTED) {
    return;
  }

  const char* resultURL = pendingIsSchedule
    ? SCHEDULE_RESULT_URL
    : MANUAL_RESULT_URL;

  HTTPClient http;

  Serial.println("Sending feeding result...");

  http.setConnectTimeout(5000);
  http.setTimeout(8000);

  if (!http.begin(resultURL)) {
    Serial.println("Could not start result request.");
    return;
  }

  http.addHeader("Content-Type", "application/json");

  JsonDocument doc;

  if (pendingIsSchedule) {
    doc["schedule_id"] = pendingCommandId;
  } else {
    doc["command_id"] = pendingCommandId;
  }

  doc["status"] = pendingResultStatus;
  doc["dispense_seconds"] = pendingDispenseSeconds;

  String requestBody;
  serializeJson(doc, requestBody);

  Serial.print("Result POST body: ");
  Serial.println(requestBody);

  int httpCode = http.POST(requestBody);
  String response = http.getString();

  Serial.print("Result HTTP: ");
  Serial.println(httpCode);
  Serial.println(response);

  http.end();

  if (httpCode != HTTP_CODE_OK &&
      httpCode != HTTP_CODE_CREATED) {
    Serial.println("Result not confirmed. Will retry.");
    return;
  }

  JsonDocument responseDoc;

  if (deserializeJson(responseDoc, response) ||
      !responseDoc["success"].is<bool>() ||
      !responseDoc["success"].as<bool>()) {
    Serial.println("PHP did not confirm the result.");
    return;
  }

  Serial.println("PHP confirmed the feeding result.");

  if (pendingIsSchedule) {
    Serial.println("Scheduled feeding result recorded.");
  } else {
    Serial.println("Manual feeding result recorded.");
  }

  resultPending = false;
  pendingCommandId = 0;
  pendingResultStatus = "";
  pendingIsSchedule = false;
}
