<?php
namespace Xrt\Dateparser;

/**
 * "Universal" date parser, works with locale set... take a look at run() method
 * - any full format described in pattern_hint
 * - any format specified in $date_patterns - pattern letters are defined http://www.php.net/manual/en/function.strftime.php
 *   
 */
class DateParser {
	/**
	 * Two dimensional array that stores locale specific words (month and day names)
	 * First dimension are locale identifiers, second are (months|months3|days|days3) identifying full month names, month TLAs, day names, day TLAs 
	 * 
	 * @var array
	 */
	private static $locale_date_words = array();
	
	/**
	 * alternative month names (spelling, names, genitive case...) 
	 * @var unknown_type
	 */
	private $alt_month_names;
	private $alt_month_names_no_of_brackets;
	
	/**
	 * reference to the $locale_date_words array member, referencing current locale date
	 * @var array
	 */
	private static $current_locale_date_words = null;
	
	/**
	 * If time part is not found / specified in the parsed string, sets time to this value
	 * Order of array values are hours, minutes, seconds
	 * @var array
	 */
	private $fake_time_to = array(7, 30, 59);

	/**
	 * Date patterns using "extended" strftime() syntax
	 * Extended sytax means it adds some special separator format
	 *  - . or %1 - date separator - replaced with "." for strptime() parsing, with [\.,/-] in regex parsing
	 *  - : or %2 - time separator - replaced with ":" for strptim() parsing, with [\.:-] in regex parsing
	 *  - , or %3 - day separator - usually used after day name, "," for strptim(), [,.]? for regex
	 *  - %4 - place/time separator - usually used between date and time, like "at", "on", "in"... "" for strptime(), .{0,2} for regex   
	 *  - %5 - optional %1   
	 * first two patterns are "special", fixed positions, not expanded to regex
	 * @var array
	 */
	private $date_patterns = array(
		'%c',							// Preferred date and time stamp based on local, top priority!
		'%a. %d %b %Y %H:%M:%S %z',		// RFC 2822, top priority!
		'%x',							// Preferred date representation based on locale, without the time, top priority!	 
		'%A, %m %d%3 %Y%5 %4 %H:%M', 	// Thursday, May 7, 2009 on 20:00
	    '%A, %m %d%3 %Y%5', 	        // Thursday, May 7, 2009
	    '%Y-%m-%d',                     // 2009-05-07
	    '%Y-%m-%d %H:%M',               // 2009-05-07 20:00
		'%m %d%3 %Y%5 %4 %H:%M', 	    // May 7, 2009 on 20:00
	    '%m %d%3 %Y%5', 	            // May 7, 2009
	    '%d. %m%5 %Y%5 %4 %H:%M', 	    // 7. may 2009. 20:00
		'%d.%m.%Y. %H:%Mh', 		    // 7. may 2009. 20:00h
	    '%A, %m %d, %Y %4 %H:%M',	    // Saturday, May 7, 2016
	    '%A, %d. %m%5 %4 %H:%M',	    // Saturday, 7. may on 19:00
	    '%A, %d. %m%5 %Y', 			// Saturday, 7. may 2009.
		'%A, %d. %4 %H:%M',			// 7. on 19:00 
		'%d%5 %m%5 %Y',	 			// 7. may 2009
		'%d%5 %m%5 %4 %H:%M',		// 7. may on 19:00 
		'%d%5 %m%5 %4 %Hh',			// 7. may on 19h 
	    '%d%5 %4 %H:%M',			// 7. on 19:00 
		'%d%5 %H:%M',				// 7. 19:00 
	    '%d%5 %m%5 %Hh',			// 7. may 19h 
		'%d%5 %4 %Hh',				// 7. on 19h
		'%d%5 %Hh',					// 7. 19h
		'%A, %d. %m',				// Saturday, 7. may
	    '%d%5 %m%5',				// 7. may
	    '%A, %d%5', 				// Saturday, 7.
		'%m-%d-%Y',					// 05-21-2009	 
		'%m/%d/%Y',					// 05/21/2009
		'%d.%m.%Y. %Hh',			// 01.12.2009. 19h
		'%m. %d.',					// may 7.	 
		'%m/%d',					// 05/21
	);
	private $date_pattern_extensions = array(
		'strptime' => array(
			'%1'	=> '.',
			'%2'	=> ':',
			'%3'	=> ',',
			'%4'	=> '',
			'%5'	=> '',
			'  '	=> ' ',
		),
		'regex' => array(	
			'.' 	=> '[\.,/-]',
			'%1'	=> '[\.,/-]',
			':'		=> '[\.:-]',
			'%2'	=> '[\.:-]',
			','		=> '[,.]?',
			'%3'	=> '[,.]?',
			' %4 '	=> '\s*.{0,2}\s*',
			'%4'	=> '.{0,2}',
			'%5'	=> '[\.,/-]?',
		)
	);
	
	/**
	 * Date part abbrevation synonyms... well, kind of synonyms
	 * Class try to tweak pattern to match any abbr synonym
	 * Order or "synonyms" IS important, put them from most to least specific, fx. %Y before %y! 
	 * 
	 * @var array
	 */
	private $datepart_synonyms = array(
		'day' => array('%A', '%a'),	
		'mday' => array('%d%O', '%e%O', '%d', '%e'),
		'month' => array('%B', '%h', '%b', '%m'),
		'year' => array('%Y', '%y'),
		'hour' => array('%I %p', '%I %P', '%l %p', '%l %P', '%H'),
		'min' => array('%M %p', '%M %P', '%M'),
		'sec' => array('%S'),
	);
	
	/**
	 * utility variable for pattern "synonyms", counting number of variations of the pattern
	 * fx. pattern '%A, %d. %m %Y' is considered the same as '%a, %e. %B %y' (and many others), has_parts takes care of number of variations 
	 * and helps run() method to itereate thru all variations
	 * $this->has_parts[$datepart] = $regex_pattern, $regex pattern is something like (%d|%e)
	 * 
	 * @var array
	 */
	private $has_part = array();
	
	private $locale_failed_string = null;
	
	/**
	 * Number of iterations / patterns tried to match against string
	 * @var integer
	 */
	public $parse_tries;
	
	/**
	 * remembers last succesful pattern to match against $str, array has two fields, 'strptime' and 'regex' 
	 * used for optimization purpose only, run() method first tries last pattern used
	 * @var array
	 */
	private $recently_used_patterns = array();
	
	/**
	 * Regex defines what is the space (\s, &nbsp;...)
	 * @var string
	 */
	private $space_regex_pattern;
	
	/**
	 * Maps strftime() patterns to reg.ex. (sub)subpatterns
	 * array(
	 * 		datepart => array(regex, date identifier) - should be used for numeric values
	 * 		datepart => array(regex, date identifier, no_of_brackets_used) - should be used for numeric values
	 * 		datepart => array(regex, date identifier, no_of_brackets_used, callback) - textual representation that need further transformation into numeric value
	 * 		datepart => array(regex, date identifier, no_of_brackets_used, string) - string indicating special case - month or day name or abbrevation
	 * )
	 * 
	 * 
	 * @var array
	 */
	private $strptime2regex_map = array(
		// day
		"%A" => array("(\w+)", 'tm_mday', 1, 'day'),			// A full textual representation of the day of the week
        "%a" => array("(\w{3})", 'tm_mday', 1, 'd'),			// Mon, Tue... 
		"%d" => array("(\d{2})", 'tm_mday'),					// Day of the month, 2 digits with leading zeros
        "%e" => array("(\d{1,2})", 'tm_mday'),					// Day of the month without leading zeros

		 // month
		"%B" => array("(\w+)", 'tm_mon', 1, 'month'),			// A full textual representation of a month, such as January or March
		"%m" => array("((1[0-2]|0?[1-9]))", 'tm_mon', 2),		// Numeric representation of a month
		"%b" => array("(\w{3})", 'tm_mon', 1, 'mon'),			// A short textual representation of a month, three letters
	    "%h" => array("(\w{3})", 'tm_mon', 1, 'mon'),			// A short textual representation of a month, three letters
		
		// year
        "%Y" => array("(\d{4})", 'tm_year'),					// A full numeric representation of a year, 4 digits
        "%y" => array("(\d{2})", 'tm_year'),					// A two digit representation of a year

		// time
        "%I" => array("([01]?\d)", 'tm_hour'),						// 12-hour format of an hour
        "%H" => array("((1[0-9]|2[0-4]|0?[1-9]))", 'tm_hour', 2),	// 24-hour format of an hour
		"%M" => array("([0-5][0-9])", 'tm_min'),					// Minutes with leading zeros
       	"%S" => array("([0-5][0-9])", 'tm_sec'),					// Seconds, with leading zeros
       	
		// am/pm
		"%p" => array("(am|pm)", 'tm_am_or_pm', 1),				// Ante meridiem and Post meridiem
		"%P" => array("(AM|PM)", 'tm_am_or_pm', 1),				// Ante meridiem and Post meridiem
		
		'%O' => array("(st|th|nd|rd)", 'tm_ordinal', 1)
	);
	
	protected $strategiesEnabled = [];
	
	public function __construct($alt_month_names = null, $alt_month_names_no_of_brackets = 0) {
		$this->space_regex_pattern = "(\s|&nbsp;|&#160;|&#x00A0;|" . chr(194) . chr(160) . "|" .  chr(160) . ")*";
		if ($alt_month_names) $this->set_month_alternate_names($alt_month_names, $alt_month_names_no_of_brackets);
	}

	public function set_month_alternate_names($alt_month_names, $no_of_brackets, $reset = false) {
		if (!$reset) $orig = array($this->alt_month_names, $this->alt_month_names_no_of_brackets);
		
		$this->alt_month_names = $alt_month_names;
		$this->alt_month_names_no_of_brackets = $no_of_brackets;
		
		if (!$reset) return $orig;
		else return null;
	}
	
 /** 
  * "Universal" date parsing function... can be called in many different ways. $str is a string to parse
  *   
  * @param string $str input date and time in virtually any format
  * @param string $pattern_hint - preg match pattern or strptim() format string
  * @param mixed $locale_regex - is it regex (true) or locale string / array of strings or do nothing (false). Do nothing means locale are already set, pattern is not a regex 
  * @param array $pattern_map mapping matched string into $parsed string 
  * @return array containing 
  * 	gmt gmt string - GMT date formated as yyyy-mm-dd hh:ii:ss
  * 	gmt_ts integer - GMT unix timestamp
  * 	time_faked boolean - time part may be faked (true) or found in $str (false). Faked time would have 59 value for seconds (defined in $fake_time_to)  
  * 	raw_input string - input string ($str)
  * 	unparsed string - unparsed part of the string
  * 	method_used integer - useful for debugging: 
  * 		1 - used pattern_hint and strptime() php function, 
  * 		2 - used pattern_hint as regex (and pattern_map)
  * 		96 - matches last used strptime() pattern again
  * 		97 - matches last used regex pattern again 
  * 		98 - predefined strptime() formats
  * 		99 - predefined strptime() formats using regex to match 
  * 	pattern_used string - pattern that succesfully matched date
  */
	public function run($str, $pattern_hint = '', $locale_regex = true, $pattern_map = array()) {
		$this->parse_tries = 0;
		$old_locale = null;
		if (is_string($locale_regex) || is_array($locale_regex)) {
			$old_locale = setlocale(LC_TIME, '0'); 
			$locale_set = setlocale(LC_TIME, $locale_regex);
			if (!$locale_set) {
				$this->locale_failed_string = $locale_regex;
			}
			$pattern_regex = false;
		} else {
			$pattern_regex = $locale_regex; 
		}
		
		$ret = array();
		if ($pattern_hint && !$pattern_regex) {
			// we have a format hint, and locale is set
			// performance issue - srtptime is slow, regex would do the same
			/*
			$parsed = strptime($str, $pattern_hint);
			$this->parse_tries++;
			if ($parsed && strcmp($parsed['unparsed'], $str)) {
				$ret = $this->tweak_date_form_output($parsed, $pattern_hint, $str, 1);
			}
			*/
		}
		
		if (!$ret) {		
			$this->set_date_words();
		}
		
		if (!$ret && $pattern_hint && $pattern_map) {
			// Plan B - do the regex match, with $pattern_map
			if (!is_array($pattern_hint)) { 
				// encapsulate in array
				$pattern_hint = array($pattern_hint);
				$pattern_maps = array($pattern_map);
			}
			foreach ($pattern_hint as $key => $pattern) {
				if ($parsed = $this->pattern_match_regex($str, $pattern, $pattern_maps[$key])) {
					$ret = $this->tweak_date_form_output($parsed, $pattern, $str, 2);
					break;
				}
			}
		}

		if (!$ret) {
			// plan C - try to use most recently used pattern (that mathed previous $str)
			// performance issue - srtptime is slow, regex would do the same
			/*
			if ($this->recently_used_patterns['strptime']) {
				$parsed = strptime($str, $this->recently_used_patterns['strptime']);
				if ($parsed && strcmp($parsed['unparsed'], $str)) {
					$found = true;
					$ret = $this->tweak_date_form_output($parsed, $this->recently_used_patterns['strptime'], $str, 96);
				}
			}
			*/
			
			$best_match = array();
			if ((!$ret || trim($ret['unparsed'])) && (isset($this->recently_used_patterns['regex']) && $this->recently_used_patterns['regex'])) {
				list($pattern, $pattern_map) = $this->strptime2regex($this->recently_used_patterns['regex']);
				if ($parsed = $this->pattern_match_regex($str, $pattern, $pattern_map)) {
					if (!$parsed['unparsed']) { // full match, look no further
						$found = true;
						$ret = $this->tweak_date_form_output($parsed, $this->recently_used_patterns['regex'], $str, 97, $pattern);
					} else {
					    if (!$best_match || (strlen($best_match[0]['unparsed']) > strlen($parsed['unparsed']))) {
							$best_match = array($parsed, $this->recently_used_patterns['regex'], $pattern);
						}
					}
				}
			}
		}
		
		
		if (!$ret || trim($ret['unparsed'])) {
			// plan D - try predefined patterns
			// performance issue - commented out - srtptime is slow, regex would do the same
			/*
			$found = false;
			$date_patterns = array(); // temporary array, with separators replaced
			foreach ($this->date_patterns as $value) {
				$date_patterns[] = strtr($value, $this->date_pattern_extensions['strptime']);
			}
			$date_patterns = array_unique($date_patterns);
			$this->recently_used_patterns['strptime'] = '';
			foreach ($date_patterns as $date_pattern) {
				$this->has_parts = array();
				foreach ($this->datepart_synonyms as $datepart => $datepart_synonym) {
					$regex_pattern = '(' . join('|', $datepart_synonym) . ')';
					if (preg_match("~$regex_pattern~", $date_pattern)) $this->has_parts[$datepart] = $regex_pattern;			
				}
				$no_of_iterations = 1;
				foreach ($this->has_parts as $datepart => $regex_pattern) $no_of_iterations *= count($this->datepart_synonyms[$datepart]);
				for ($iteration_no = 0; $iteration_no < $no_of_iterations; $iteration_no++) {
					$pattern_by_iteration = $this->pattern_by_iteration_no($date_pattern, $iteration_no, $no_of_iterations);
					$this->parse_tries++;
					$parsed = strptime($str, $pattern_by_iteration);
					if ($parsed && strcmp($parsed['unparsed'], $str)) {
						$found = true;
						$this->recently_used_patterns['strptime'] = $pattern_by_iteration;
						$ret = $this->tweak_date_form_output($parsed, $pattern_by_iteration, $str, 98);
						break;
					}
				}
				if ($found) break;
			}
			*/
			
			if (!$ret || trim($ret['unparsed'])) {
				$found = false;
				// Plan E, maybe we can do it better with regex
				foreach ($this->date_patterns as $key => $date_pattern) {
					if ($key < 3) continue; // fixed patterns used in strptime() only
					$date_pattern = strtr($date_pattern, $this->date_pattern_extensions['regex']);
					$this->has_parts = array();
					foreach ($this->datepart_synonyms as $datepart => $datepart_synonym) {
						$regex_pattern = '(' . join('|', $datepart_synonym) . ')';
						if (preg_match("~$regex_pattern~", $date_pattern)) $this->has_parts[$datepart] = $regex_pattern;			
					}
					$no_of_iterations = 1;
					foreach ($this->has_parts as $datepart => $regex_pattern) $no_of_iterations *= count($this->datepart_synonyms[$datepart]);
					for ($iteration_no = 0; $iteration_no < $no_of_iterations; $iteration_no++) {
						$pattern_by_iteration = $this->pattern_by_iteration_no($date_pattern, $iteration_no, $no_of_iterations);
						list($pattern, $pattern_map) = $this->strptime2regex($pattern_by_iteration);
						if ($parsed = $this->pattern_match_regex($str, $pattern, $pattern_map)) {
							if (!$parsed['unparsed']) { // full match, look no further
								$found = true;
								$this->recently_used_patterns['regex'] = $pattern_by_iteration;
								$ret = $this->tweak_date_form_output($parsed, $pattern_by_iteration, $str, 99, $pattern);
								break;
							} else {
							    if (!$best_match || (strlen($best_match[0]['unparsed']) > strlen($parsed['unparsed']))) {
									$this->recently_used_patterns['regex'] = $pattern_by_iteration;
									$best_match = array($parsed, $pattern_by_iteration, $pattern);
								}
							}
						}
					}
					if ($found) break;
				}
				if (!$found && $best_match) $ret = $this->tweak_date_form_output($best_match[0], $best_match[1], $str, 99, $best_match[2]);
			}
		}
		
		if (isset($old_locale)) setlocale(LC_TIME, $old_locale);

		return $ret;
	}

	private function pattern_by_iteration_no($pattern, $iteration_no, $no_of_iterations) {
		$iteration_left = $iteration_no;
		foreach ($this->has_parts as $datepart => $regex) {
			$no_of_synonyms = count($this->datepart_synonyms[$datepart]);
			$no_of_iterations = (int)($no_of_iterations / $no_of_synonyms);
			if ($no_of_iterations) {
				$pattern = preg_replace("~$regex~", $this->datepart_synonyms[$datepart][(int)($iteration_left / $no_of_iterations)], $pattern);
				$iteration_left = $iteration_left % $no_of_iterations;
			} else {
				$pattern = preg_replace("~$regex~", $this->datepart_synonyms[$datepart][0], $pattern);
				$iteration_left = 0;
			}
		}
//		echo "pattern: $pattern, iteration_no: $iteration_no / $no_of_iterations\n";
		return $pattern;
	}

	/**
	 * Tries to match $str against regex pattern
	 * 
	 * @param $str
	 * @param $pattern
	 * @param $pattern_map
	 * @return array
	 */
	private function pattern_match_regex($str, $pattern, $pattern_map) {
		$this->parse_tries++;
		$parsed = array();
		$matches = array();
		if (preg_match($pattern, $str, $matches)) {
			foreach ($pattern_map as $datepart => $bracket_order) {
				if (is_numeric($bracket_order)) {
					$parsed[$datepart] = $matches[$bracket_order];
				} elseif (is_string($bracket_order) && function_exists($bracket_order)) {
					$parsed[$datepart] = call_user_func($bracket_order, $matches);
				} elseif (is_array($bracket_order) && isset($bracket_order[0]) && is_object($bracket_order[0]) && method_exists($bracket_order[0], $bracket_order[1])) {
					$parsed[$datepart] = call_user_func($bracket_order, $matches);
				} elseif (is_array($bracket_order)) {
					if (is_array($bracket_order[1])) {
						// array(0 => matches array index, 1 => array('january', 'february', ... ));
						$parsed[$datepart] = array_search($matches[$bracket_order[0]], $bracket_order[1]);
					} else {
						// special cases, 
						// array(0 => matches array index, 1 => 'month' or 'mon' or 'day' or 'd')
						switch ($bracket_order[1]) {
							case 'month': // full month name
								if ($this->alt_month_names) {
									$parsed[$datepart] = key(preg_grep("~{$matches[$bracket_order[0]]}~i", array_merge(self::$current_locale_date_words['months'], $this->alt_month_names))) % 12 + 1;
								} else {
									$parsed[$datepart] = key(preg_grep("~{$matches[$bracket_order[0]]}~i", self::$current_locale_date_words['months'])) % 12 + 1;
								}
								break;
							case 'mon': // month name abbreviated
								$parsed[$datepart] = key(preg_grep("~{$matches[$bracket_order[0]]}~i", self::$current_locale_date_words['months3'])) %12 + 1;
								break;
							case 'day': // day of the week
								$parsed[$datepart] = key(preg_grep("~{$matches[$bracket_order[0]]}~i", self::$current_locale_date_words['days']));
								break; 
							case 'd': // day of the week abbreviated
								$parsed[$datepart] = key(preg_grep("~{$matches[$bracket_order[0]]}~i", self::$current_locale_date_words['days3']));
								break; 
						}
					}							
				}
				$parsed['unparsed'] = preg_replace($pattern, '', $str, 1);
			}
		}
		return $parsed;
	} 
	
	/**
	 * Sets $locale_date_words according to current locale, tweaks $strptime2regex_map array  
	 */
	private function set_date_words() {
		if ($this->locale_failed_string) {
			$current_locale = $this->locale_failed_string;
		} else {
			$current_locale = setlocale(LC_TIME, '0');
		}
		
		if (!(self::$current_locale_date_words =& self::$locale_date_words[$current_locale])) {
			self::$locale_date_words[$current_locale] = array();
			self::$current_locale_date_words =& self::$locale_date_words[$current_locale];
			
			if (!$this->locale_failed_string && function_exists('nl_langinfo')) {
				for ($i = 1; $i <= 7; $i++) {
					self::$current_locale_date_words['days3'][] = nl_langinfo(constant("ABDAY_$i"));
					self::$current_locale_date_words['days'][] = nl_langinfo(constant("DAY_$i"));
				}
				for ($i = 1; $i <= 12; $i++) {
					self::$current_locale_date_words['months3'][] = nl_langinfo(constant("ABMON_$i"));
					self::$current_locale_date_words['months'][] = nl_langinfo(constant("MON_$i"));
				}
			} else {
				$ffn_locale_static = __DIR__ . '/locale/' . strtolower($this->locale_failed_string) . '.php';
				if (file_exists($ffn_locale_static)) {
					self::$current_locale_date_words = include $ffn_locale_static;
				}
				if (!self::$current_locale_date_words) {
					self::$current_locale_date_words = include __DIR__ . '/locale/en.php';
				}
			}
		}
		$this->strptime2regex_map['%a'][0] = '(' . join('|', self::$current_locale_date_words['days3']) . ')';
		$this->strptime2regex_map['%A'][0] = '(' . join('|', self::$current_locale_date_words['days']) . ')';
		$this->strptime2regex_map['%b'][0] = '(' . join('|', self::$current_locale_date_words['months3']) . ')';
		$this->strptime2regex_map['%h'][0] = '(' . join('|', self::$current_locale_date_words['months3']) . ')';
		if (is_array($this->alt_month_names)) {
			$this->strptime2regex_map['%B'][0] = '(' . join('|', array_merge(self::$current_locale_date_words['months'], $this->alt_month_names)) . ')';
			$this->strptime2regex_map['%B'][2] += $this->alt_month_names_no_of_brackets;
		} else {
			$this->strptime2regex_map['%B'][0] = '(' . join('|', self::$current_locale_date_words['months']) . ')';
		}
		
		return $this;
	}
	
	private function strptime2regex($in_pattern) {
        $len = strlen($in_pattern);
        
        $specialchar = false;
        $space = false;
        $out_pattern = '';
        $out_pattern_map = array();
        $bracket_no = 0;
        
        for ($i = 0; $i < $len; $i++) {
        	if ($specialchar) {
        		if (isset($this->strptime2regex_map["%$in_pattern[$i]"])) {
        			$x = $this->strptime2regex_map["%$in_pattern[$i]"];
       				$out_pattern .= $x[0];
       				if (isset($x[3])) $out_pattern_map[$x[1]] = array(++$bracket_no, $x[3]);
       				else $out_pattern_map[$x[1]] = ++$bracket_no;
       				if (isset($x[2])) $bracket_no += $x[2] - 1;
        		} else {
        			$out_pattern .= "%$in_pattern[$i]";
        		}
        		$specialchar = $space = false;
        	} else {
        		if ($in_pattern[$i] == '%') {
        			$specialchar = true;
        			$space = false;
        		} elseif ($in_pattern[$i] == ' ') {
        			if (!$space) {
        				$space = true;
        				$out_pattern .= $this->space_regex_pattern; 
        				++$bracket_no; 
        			}
        			 
        		} else {
        			$out_pattern .= $in_pattern[$i];
        			$specialchar = $space = false;
        		}
        	}
        }
        return array("~$out_pattern~i", $out_pattern_map);
	}
	
	private function tweak_date_form_output($parsed, $pattern, $str, $method_used = 0, $pattern_regex = false) {
		// was there a time part? %H, %I, %l, %r, %R, %T, %X, %c, %s - are formats that include hours
		$time_faked = !preg_match('~\%[HIlrRTXcs]~', $pattern);
		if ($time_faked) {
			$parsed['tm_hour'] = $this->fake_time_to[0];
			$parsed['tm_min'] = $this->fake_time_to[1];
			$parsed['tm_sec'] = $this->fake_time_to[2];
		}
		if (!isset($parsed['tm_mon'])) $parsed['tm_mon'] = date('n');
		elseif (!$pattern_regex) ++$parsed['tm_mon']; 
		if (!isset($parsed['tm_year']) || ($parsed['tm_year'] < 0)) $parsed['tm_year'] = date('Y');
		// localized date string, convert it to gmt
		if(!isset($parsed['tm_sec'])) $parsed['tm_sec'] = 00;
		$ts = mktime($parsed['tm_hour'], $parsed['tm_min'], $parsed['tm_sec'], $parsed['tm_mon'], $parsed['tm_mday'], $parsed['tm_year']);
		return array(
			'gmt' => gmdate('Y-m-d H:i:s', $ts),
			'gmt_ts' => $ts,
			'time_faked' => $time_faked,
			'raw_input' => $str,
			'unparsed' => $parsed['unparsed'],
			'method_used' => $method_used,
			'pattern_used' => $pattern,
			'pattern_used_autoregex' => $pattern_regex, 
			'parsed' => $parsed,
			'tries' => $this->parse_tries
		);
	}
	
}