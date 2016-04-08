<?php

final class AphrontFormDateControlValue extends Phobject {

  private $valueDate;
  private $valueTime;
  private $valueEnabled;

  private $viewer;
  private $zone;
  private $optional;

  public function getValueDate() {
    return $this->valueDate;
  }

  public function getValueTime() {
    return $this->valueTime;
  }

  public function isValid() {
    if ($this->isDisabled()) {
      return true;
    }
    return ($this->getEpoch() !== null);
  }

  public function isEmpty() {
    if ($this->valueDate) {
      return false;
    }

    if ($this->valueTime) {
      return false;
    }

    return true;
  }

  public function isDisabled() {
    return ($this->optional && !$this->valueEnabled);
  }

  public function setEnabled($enabled) {
    $this->valueEnabled = $enabled;
    return $this;
  }

  public function setOptional($optional) {
    $this->optional = $optional;
    return $this;
  }

  public function getOptional() {
    return $this->optional;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public static function newFromParts(
    PhabricatorUser $viewer,
    $year,
    $month,
    $day,
    $time = null,
    $enabled = true) {

    $value = new AphrontFormDateControlValue();
    $value->viewer = $viewer;
    list($value->valueDate, $value->valueTime) =
      $value->getFormattedDateFromParts(
        $year,
        $month,
        $day,
        coalesce($time, '12:00 AM'));
    $value->valueEnabled = $enabled;

    return $value;
  }

  public static function newFromRequest(AphrontRequest $request, $key) {
    $value = new AphrontFormDateControlValue();
    $value->viewer = $request->getViewer();

    $datetime = $request->getStr($key);
    if (strlen($datetime)) {
      $date = $datetime;
      $time = null;
    } else {
      $date = $request->getStr($key.'_d');
      $time = $request->getStr($key.'_t');
    }

    // If this looks like an epoch timestamp, prefix it with "@" so that
    // DateTime() reads it as one. Assume small numbers are a "Ymd" digit
    // string instead of an epoch timestamp for a time in 1970.
    if (ctype_digit($date) && ($date > 30000000)) {
      $date = '@'.$date;
      $time = null;
    }

    $value->valueDate = $date;
    $value->valueTime = $time;

    $formatted = $value->getFormattedDateFromDate(
      $value->valueDate,
      $value->valueTime);

    if ($formatted) {
      list($value->valueDate, $value->valueTime) = $formatted;
    }

    $value->valueEnabled = $request->getStr($key.'_e');
    return $value;
  }

  public static function newFromEpoch(PhabricatorUser $viewer, $epoch) {
    $value = new AphrontFormDateControlValue();
    $value->viewer = $viewer;

    if (!$epoch) {
      return $value;
    }

    $readable = $value->formatTime($epoch, 'Y!m!d!g:i A');
    $readable = explode('!', $readable, 4);

    $year  = $readable[0];
    $month = $readable[1];
    $day   = $readable[2];
    $time  = $readable[3];

    list($value->valueDate, $value->valueTime) =
      $value->getFormattedDateFromParts(
        $year,
        $month,
        $day,
        $time);

    return $value;
  }

  public static function newFromDictionary(
    PhabricatorUser $viewer,
    array $dictionary) {
    $value = new AphrontFormDateControlValue();
    $value->viewer = $viewer;

    $value->valueDate = idx($dictionary, 'd');
    $value->valueTime = idx($dictionary, 't');

    $formatted = $value->getFormattedDateFromDate(
      $value->valueDate,
      $value->valueTime);

    if ($formatted) {
      list($value->valueDate, $value->valueTime) = $formatted;
    }

    $value->valueEnabled = idx($dictionary, 'e');

    return $value;
  }

  public static function newFromWild(PhabricatorUser $viewer, $wild) {
    if (is_array($wild)) {
      return self::newFromDictionary($viewer, $wild);
    } else if (is_numeric($wild)) {
      return self::newFromEpoch($viewer, $wild);
    } else {
      throw new Exception(
        pht(
          'Unable to construct a date value from value of type "%s".',
          gettype($wild)));
    }
  }

  public function getDictionary() {
    return array(
      'd' => $this->valueDate,
      't' => $this->valueTime,
      'e' => $this->valueEnabled,
    );
  }

  public function getValueAsFormat($format) {
    return phabricator_format_local_time(
      $this->getEpoch(),
      $this->viewer,
      $format);
  }

  private function formatTime($epoch, $format) {
    return phabricator_format_local_time(
      $epoch,
      $this->viewer,
      $format);
  }

  public function getEpoch() {
    if ($this->isDisabled()) {
      return null;
    }

    $datetime = $this->newDateTime($this->valueDate, $this->valueTime);
    if (!$datetime) {
      return null;
    }

    return $datetime->format('U');
  }

  private function getTimeFormat() {
    return $this->getViewer()
      ->getPreference(PhabricatorUserPreferences::PREFERENCE_TIME_FORMAT);
  }

  private function getDateFormat() {
    return $this->getViewer()
      ->getPreference(PhabricatorUserPreferences::PREFERENCE_DATE_FORMAT);
  }

  private function getFormattedDateFromDate($date, $time) {
    $datetime = $this->newDateTime($date, $time);
    if (!$datetime) {
      return null;
    }

    return array(
      $datetime->format($this->getDateFormat()),
      $datetime->format($this->getTimeFormat()),
    );

    return array($date, $time);
  }

  private function newDateTime($date, $time) {
    $date = $this->getStandardDateFormat($date);
    $time = $this->getStandardTimeFormat($time);
    try {
      $datetime = new DateTime("{$date} {$time}");
    } catch (Exception $ex) {
      return null;
    }

    // Set the timezone explicitly because it is ignored in the constructor
    // if the date is an epoch timestamp.
    $zone = $this->getTimezone();
    $datetime->setTimezone($zone);

    return $datetime;
  }

  private function getFormattedDateFromParts(
    $year,
    $month,
    $day,
    $time) {

    $zone = $this->getTimezone();
    $date_time = id(new DateTime("{$year}-{$month}-{$day} {$time}", $zone));

    return array(
      $date_time->format($this->getDateFormat()),
      $date_time->format($this->getTimeFormat()),
    );
  }

  private function getFormatSeparator() {
    $format = $this->getDateFormat();
    switch ($format) {
      case 'n/j/Y':
        return '/';
      default:
        return '-';
    }
  }

  public function getDateTime() {
    return $this->newDateTime();
  }

  private function getTimezone() {
    if ($this->zone) {
      return $this->zone;
    }

    $viewer_zone = $this->viewer->getTimezoneIdentifier();
    $this->zone = new DateTimeZone($viewer_zone);
    return $this->zone;
  }

  private function getStandardDateFormat($date) {
    $colloquial = array(
      'newyear' => 'January 1',
      'valentine' => 'February 14',
      'pi' => 'March 14',
      'christma' => 'December 25',
    );

    // Lowercase the input, then remove punctuation, a "day" suffix, and an
    // "s" if one is present. This allows all of these to match. This allows
    // variations like "New Year's Day" and "New Year" to both match.
    $normalized = phutil_utf8_strtolower($date);
    $normalized = preg_replace('/[^a-z]/', '', $normalized);
    $normalized = preg_replace('/day\z/', '', $normalized);
    $normalized = preg_replace('/s\z/', '', $normalized);

    if (isset($colloquial[$normalized])) {
      return $colloquial[$normalized];
    }

    $separator = $this->getFormatSeparator();
    $parts = preg_split('@[,./:-]@', $date);
    return implode($separator, $parts);
  }

  private function getStandardTimeFormat($time) {
    $colloquial = array(
      'crack of dawn' => '5:00 AM',
      'dawn' => '6:00 AM',
      'early' => '7:00 AM',
      'morning' => '8:00 AM',
      'elevenses' => '11:00 AM',
      'morning tea' => '11:00 AM',
      'noon' => '12:00 PM',
      'high noon' => '12:00 PM',
      'lunch' => '12:00 PM',
      'afternoon' => '2:00 PM',
      'tea time' => '3:00 PM',
      'evening' => '7:00 PM',
      'late' => '11:00 PM',
      'witching hour' => '12:00 AM',
      'midnight' => '12:00 AM',
    );

    $normalized = phutil_utf8_strtolower($time);
    if (isset($colloquial[$normalized])) {
      $time = $colloquial[$normalized];
    }

    return $time;
  }

}
