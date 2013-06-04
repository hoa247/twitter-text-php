<?php

/**
 * Wrapper class for Regex strings (handles joining of Regex modifiers and using #{name} templates)
 */
class TwttrTxtRegex {

	const MODIFIER_GREEDY = ''; // greedy (php is greedy by default) (/u for ungreedy)
	const MODIFIER_MULTILINE = 'm'; // multiline
	const MODIFIER_CASEINSENSITIVE = 'i'; // case-insensitive
	const MODIFIER_UTF8 = 'u';

	private $_source = '';
	private $_flags = array(
		self::MODIFIER_UTF8 => true // make sure we're in UTF8 mode for php regexes (http://php.net/manual/en/reference.pcre.pattern.modifiers.php)
	);

	public function __construct($regex, $flags = null) {
		if (is_string($regex)) {
			if (mb_strpos($regex, '/') === 0) {
				// "/regex/" or "/regex/iu"
				$parts = explode('/', $regex);
				$origParts = $parts;
				$partsFlags = array_pop($parts);
				if ($partsFlags) {
					$this->_addFlagsAsString($partsFlags);
				}
				array_shift($parts);
				$this->_source = implode('/', $parts);
			}
			else {
				// regex
				$this->_source = $regex;
			}
		}
		elseif ($regex instanceof TwttrTxtRegex) {
			$this->_source = $regex->getSource();
			$this->_flags = $regex->getFlags();
		}

		if ($flags) {
			$this->_addFlagsAsString($flags);
		}
	}

	private function _addFlagsAsString($flags) {
		$flagsArray = array();
		for ($i = 0; $i < strlen($flags); $i++) {
			if (!in_array($flags[$i], array(self::MODIFIER_CASEINSENSITIVE, self::MODIFIER_UTF8, self::MODIFIER_GREEDY, self::MODIFIER_MULTILINE), true)) {
				throw new Exception($flags[$i] . " is not a valid flag");
			}
			$flagsArray[] = $flags[$i];
		}
		$this->addFlags($flagsArray);
	}

	public function addFlags(array $flags) {
		foreach($flags as $flag) {
        	$this->_flags[$flag] = true;
		}
	}

	public function getFlags() {
		return array_keys($this->_flags);
	}

	public function getSource() {
		return $this->_replacePlaceHolders($this->_source);
	}

	private function _replacePlaceHolders($str) {
		$that = $this;
		$origStr = $str;
		$str = preg_replace_callback(
	        '/#\{(\w+)\}/',
	        function ($matches) use ($that) {
	        	if (TwttrTxt::$regexen[$matches[1]]) {
	        		$obj = TwttrTxt::$regexen[$matches[1]];
	        		if (is_string($obj)) {
	        			$obj = new TwttrTxtRegex($obj);
	        		}
	        		$that->addFlags($obj->getFlags());
	        		return $obj->getSource();
	        	}
	        	return "";
	        },
	        $str
        );
        return $str;
	}

	public function __toString() {
		$result = '/' . $this->getSource() . '/' . implode('', $this->getFlags());
		return $result;
	}
}

/**
 * PHP port of the twitter.text.js Javascript library
 */
class TwttrTxt {

	private static $_inited = false;

	public static $regexen = array();

	private static $_HTML_ENTITIES = array(
	    '&' => '&amp;',
	    '>' => '&gt;',
	    '<' => '&lt;',
	    '"' => '&quot;',
	    "'" => '&#39;'
	  );

	// array extend (for default $options arguments)
	private static function _extend($array, $defaults) {
		if (!is_array($array)) {
			$array = array();
		}
		if (!is_array($defaults)) {
			return $array;
		}

		foreach($defaults as $key => $value) {
			if (!array_key_exists($key, $array)) {
				$array[$key] = $value;
			}
		}
		return $array;
	}

	// HTML escaping
  	public static function htmlEscape($text) {
  		throw Exception("not multibyte safe");
		return str_replace(array_keys(self::$_HTML_ENTITIES), array_values(self::$_HTML_ENTITIES), $text);
	}

	/**
	 * Builds a TwttrTxtRegex wrapper
	 *
	 * @param  [type] $regex [description]
	 * @param  [type] $flags [description]
	 * @return [type]        [description]
	 */
	public static function regexSupplant($regex, $flags = null) {
		return new TwttrTxtRegex($regex, $flags);
	}

	/**
	 * Simple string interpolation
	 *
	 * @param  string  $str 		 The template
	 * @param  array   $values       The dictionary replace
	 * @return string
	 */
	public static function stringSupplant($str, array $values) {
		$str = preg_replace_callback(
	        '/#\{(\w+)\}/',
	        function ($matches) use ($values) {
	        	return $values[$matches[1]] ? ("" . $values[$matches[1]]) : "";
	        },
	        $str
        );
        return $str;
    }

    /**
     * Expects the HEX representation of a UTF8-character.
     *
     * @param  string $char [description]
     * @return string The UTF-8 character
     */
    public static function fromCharCode($char) {
    	$result = json_decode('"\u' . $char . '"');
    	if (is_null($result)) {
    		throw new Exception("Could not create character for code $char");
    	}
    	return $result;
    }

    public static function addCharsToCharClass(array &$charClass, $start, $end) {
		$s = self::fromCharCode($start);
		if ($end !== $start) {
			$s .= "-" . self::fromCharCode($end);
		}
		$charClass[] = $s;
	}

	private static $_UNICODE_SPACES = array();
	private static $_INVALID_CHARS = array();

	public static function init() {
		if (self::$_inited) {
			return;
		}

		// Space is more than %20, U+3000 for example is the full-width space used with Kanji. Provide a short-hand
		// to access both the list of characters and a pattern suitible for use with String#split
		// Taken from: ActiveSupport::Multibyte::Handlers::UTF8Handler::UNICODE_WHITESPACE
		self::$_UNICODE_SPACES = array(
			self::fromCharCode('0020'), // White_Space # Zs       SPACE
			self::fromCharCode('0085'), // White_Space # Cc       <control-0085>
			self::fromCharCode('00A0'), // White_Space # Zs       NO-BREAK SPACE
			self::fromCharCode('1680'), // White_Space # Zs       OGHAM SPACE MARK
			self::fromCharCode('180E'), // White_Space # Zs       MONGOLIAN VOWEL SEPARATOR
			self::fromCharCode('2028'), // White_Space # Zl       LINE SEPARATOR
			self::fromCharCode('2029'), // White_Space # Zp       PARAGRAPH SEPARATOR
			self::fromCharCode('202F'), // White_Space # Zs       NARROW NO-BREAK SPACE
			self::fromCharCode('205F'), // White_Space # Zs       MEDIUM MATHEMATICAL SPACE
			self::fromCharCode('3000')  // White_Space # Zs       IDEOGRAPHIC SPACE
		);
		self::addCharsToCharClass(self::$_UNICODE_SPACES, '0009', '000D'); // White_Space # Cc   [5] <control-0009>..<control-000D>
  		self::addCharsToCharClass(self::$_UNICODE_SPACES, '2000', '200A'); // White_Space # Zs  [11] EN QUAD..HAIR SPACE

		self::$_INVALID_CHARS = array(
			self::fromCharCode('FFFE'),
		    self::fromCharCode('FEFF'), // BOM
		    self::fromCharCode('FFFF') // Special
  		);
  		self::addCharsToCharClass(self::$_INVALID_CHARS, '202A', '202E'); // Directional change

		self::$regexen['spaces_group'] = self::regexSupplant(implode('', self::$_UNICODE_SPACES));
		self::$regexen['spaces'] = self::regexSupplant("[" + implode('', self::$_UNICODE_SPACES) + "]");
		self::$regexen['invalid_chars_group'] = self::regexSupplant(implode('', self::$_INVALID_CHARS));
		self::$regexen['punct'] = self::regexSupplant("/\!'#%&'\(\)*\+,\\\\\-\.\/:;<=>\?@\[\]\^_{|}~\$/"); // extra escaping for the "-" sign ... why oh why - @oemebamo
		self::$regexen['rtl_chars'] = self::regexSupplant("/[" . self::fromCharCode('0600') . "-" . self::fromCharCode('06FF') . "]|[" . self::fromCharCode('0750') . "-" . self::fromCharCode('077F') . "]|[" . self::fromCharCode('0590') . "-" . self::fromCharCode('05FF') . "]|[" . self::fromCharCode('FE70') . "-" . self::fromCharCode('FEFF') . "]/" . TwttrTxtRegex::MODIFIER_MULTILINE . TwttrTxtRegex::MODIFIER_GREEDY);
		self::$regexen['non_bmp_code_pairs'] = self::regexSupplant("/[" . self::fromCharCode('D800') . "-" . self::fromCharCode('DBFF') . "][" . self::fromCharCode('DC00') . "-" . self::fromCharCode('DFFF') . "]/" . TwttrTxtRegex::MODIFIER_MULTILINE . TwttrTxtRegex::MODIFIER_GREEDY);

		$nonLatinHashtagChars = array();
		// Cyrillic
		self::addCharsToCharClass($nonLatinHashtagChars, '0400', '04ff'); // Cyrillic
		self::addCharsToCharClass($nonLatinHashtagChars, '0500', '0527'); // Cyrillic Supplement
		self::addCharsToCharClass($nonLatinHashtagChars, '2de0', '2dff'); // Cyrillic Extended A
		self::addCharsToCharClass($nonLatinHashtagChars, 'a640', 'a69f'); // Cyrillic Extended B
		// Hebrew
		self::addCharsToCharClass($nonLatinHashtagChars, '0591', '05bf'); // Hebrew
		self::addCharsToCharClass($nonLatinHashtagChars, '05c1', '05c2');
		self::addCharsToCharClass($nonLatinHashtagChars, '05c4', '05c5');
		self::addCharsToCharClass($nonLatinHashtagChars, '05c7', '05c7');
		self::addCharsToCharClass($nonLatinHashtagChars, '05d0', '05ea');
		self::addCharsToCharClass($nonLatinHashtagChars, '05f0', '05f4');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb12', 'fb28'); // Hebrew Presentation Forms
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb2a', 'fb36');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb38', 'fb3c');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb3e', 'fb3e');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb40', 'fb41');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb43', 'fb44');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb46', 'fb4f');
		// Arabic
		self::addCharsToCharClass($nonLatinHashtagChars, '0610', '061a'); // Arabic
		self::addCharsToCharClass($nonLatinHashtagChars, '0620', '065f');
		self::addCharsToCharClass($nonLatinHashtagChars, '066e', '06d3');
		self::addCharsToCharClass($nonLatinHashtagChars, '06d5', '06dc');
		self::addCharsToCharClass($nonLatinHashtagChars, '06de', '06e8');
		self::addCharsToCharClass($nonLatinHashtagChars, '06ea', '06ef');
		self::addCharsToCharClass($nonLatinHashtagChars, '06fa', '06fc');
		self::addCharsToCharClass($nonLatinHashtagChars, '06ff', '06ff');
		self::addCharsToCharClass($nonLatinHashtagChars, '0750', '077f'); // Arabic Supplement
		self::addCharsToCharClass($nonLatinHashtagChars, '08a0', '08a0'); // Arabic Extended A
		self::addCharsToCharClass($nonLatinHashtagChars, '08a2', '08ac');
		self::addCharsToCharClass($nonLatinHashtagChars, '08e4', '08fe');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fb50', 'fbb1'); // Arabic Pres. Forms A
		self::addCharsToCharClass($nonLatinHashtagChars, 'fbd3', 'fd3d');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fd50', 'fd8f');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fd92', 'fdc7');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fdf0', 'fdfb');
		self::addCharsToCharClass($nonLatinHashtagChars, 'fe70', 'fe74'); // Arabic Pres. Forms B
		self::addCharsToCharClass($nonLatinHashtagChars, 'fe76', 'fefc');
		self::addCharsToCharClass($nonLatinHashtagChars, '200c', '200c'); // Zero-Width Non-Joiner
		// Thai
		self::addCharsToCharClass($nonLatinHashtagChars, '0e01', '0e3a');
		self::addCharsToCharClass($nonLatinHashtagChars, '0e40', '0e4e');
		// Hangul (Korean)
		self::addCharsToCharClass($nonLatinHashtagChars, '1100', '11ff'); // Hangul Jamo
		self::addCharsToCharClass($nonLatinHashtagChars, '3130', '3185'); // Hangul Compatibility Jamo
		self::addCharsToCharClass($nonLatinHashtagChars, 'A960', 'A97F'); // Hangul Jamo Extended-A
		self::addCharsToCharClass($nonLatinHashtagChars, 'AC00', 'D7AF'); // Hangul Syllables
		self::addCharsToCharClass($nonLatinHashtagChars, 'D7B0', 'D7FF'); // Hangul Jamo Extended-B
		self::addCharsToCharClass($nonLatinHashtagChars, 'FFA1', 'FFDC'); // half-width Hangul
		// Japanese and Chinese
		self::addCharsToCharClass($nonLatinHashtagChars, '30A1', '30FA'); // Katakana (full-width)
		self::addCharsToCharClass($nonLatinHashtagChars, '30FC', '30FE'); // Katakana Chouon and iteration marks (full-width)
		self::addCharsToCharClass($nonLatinHashtagChars, 'FF66', 'FF9F'); // Katakana (half-width)
		self::addCharsToCharClass($nonLatinHashtagChars, 'FF70', 'FF70'); // Katakana Chouon (half-width)
		self::addCharsToCharClass($nonLatinHashtagChars, 'FF10', 'FF19'); // \
		self::addCharsToCharClass($nonLatinHashtagChars, 'FF21', 'FF3A'); //  - Latin (full-width)
		self::addCharsToCharClass($nonLatinHashtagChars, 'FF41', 'FF5A'); // /
		self::addCharsToCharClass($nonLatinHashtagChars, '3041', '3096'); // Hiragana
		self::addCharsToCharClass($nonLatinHashtagChars, '3099', '309E'); // Hiragana voicing and iteration mark
		self::addCharsToCharClass($nonLatinHashtagChars, '3400', '4DBF'); // Kanji (CJK Extension A)
		self::addCharsToCharClass($nonLatinHashtagChars, '4E00', '9FFF'); // Kanji (Unified)
		// -- Disabled as it breaks the Regex.
		// self::addCharsToCharClass($nonLatinHashtagChars, '20000', '2A6DF'); // Kanji (CJK Extension B)
		// -- Disabled (by @oemebamo, @tdeconin), as we can't encode them server-side to valid UTF-8 characters (they are from non standard unicode planes)
		//    http://en.wikipedia.org/wiki/Unicode_plane
		//    http://codepoints.net/supplementary_ideographic_plane
		// self::addCharsToCharClass($nonLatinHashtagChars, '2A700', '2B73F'); // Kanji (CJK Extension C)
		// self::addCharsToCharClass($nonLatinHashtagChars, '2B740', '2B81F'); // Kanji (CJK Extension D)
		// self::addCharsToCharClass($nonLatinHashtagChars, '2F800', '2FA1F'); // Kanji (CJK supplement)
		self::addCharsToCharClass($nonLatinHashtagChars, '3003', '3003'); // Kanji iteration mark
		self::addCharsToCharClass($nonLatinHashtagChars, '3005', '3005'); // Kanji iteration mark
		self::addCharsToCharClass($nonLatinHashtagChars, '303B', '303B'); // Han iteration mark

		self::$regexen['nonLatinHashtagChars'] = self::regexSupplant(implode('', $nonLatinHashtagChars));

		$latinAccentChars = array();
		// Latin accented characters (subtracted 'D7 from the range, it's a confusable multiplication sign. Looks like "x")
		self::addCharsToCharClass($latinAccentChars, '00c0', '00d6');
		self::addCharsToCharClass($latinAccentChars, '00d8', '00f6');
		self::addCharsToCharClass($latinAccentChars, '00f8', '00ff');
		// Latin Extended A and B
		self::addCharsToCharClass($latinAccentChars, '0100', '024f');
		// assorted IPA Extensions
		self::addCharsToCharClass($latinAccentChars, '0253', '0254');
		self::addCharsToCharClass($latinAccentChars, '0256', '0257');
		self::addCharsToCharClass($latinAccentChars, '0259', '0259');
		self::addCharsToCharClass($latinAccentChars, '025b', '025b');
		self::addCharsToCharClass($latinAccentChars, '0263', '0263');
		self::addCharsToCharClass($latinAccentChars, '0268', '0268');
		self::addCharsToCharClass($latinAccentChars, '026f', '026f');
		self::addCharsToCharClass($latinAccentChars, '0272', '0272');
		self::addCharsToCharClass($latinAccentChars, '0289', '0289');
		self::addCharsToCharClass($latinAccentChars, '028b', '028b');
		// Okina for Hawaiian (it *is* a letter character)
		self::addCharsToCharClass($latinAccentChars, '02bb', '02bb');
		// Combining diacritics
		self::addCharsToCharClass($latinAccentChars, '0300', '036f');
		// Latin Extended Additional
		self::addCharsToCharClass($latinAccentChars, '1e00', '1eff');
		self::$regexen['latinAccentChars'] = self::regexSupplant(implode('', $latinAccentChars));

		// A hashtag must contain characters, numbers and underscores, but not all numbers.
		self::$regexen['hashSigns'] = self::regexSupplant("/[#＃]/");
		self::$regexen['hashtagAlpha'] = self::regexSupplant("/[a-z_#{latinAccentChars}#{nonLatinHashtagChars}]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['hashtagAlphaNumeric'] = self::regexSupplant("/[a-z0-9_#{latinAccentChars}#{nonLatinHashtagChars}]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['endHashtagMatch'] = self::regexSupplant("/^(?:#{hashSigns}|:\/\/)/");
		self::$regexen['hashtagBoundary'] = self::regexSupplant("/(?:^|$|[^&a-z0-9_#{latinAccentChars}#{nonLatinHashtagChars}])/");
		self::$regexen['validHashtag'] = self::regexSupplant("/(#{hashtagBoundary})(#{hashSigns})(#{hashtagAlphaNumeric}*#{hashtagAlpha}#{hashtagAlphaNumeric}*)/" . TwttrTxtRegex::MODIFIER_GREEDY . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// Mention related regex collection
		self::$regexen['validMentionPrecedingChars'] = self::regexSupplant("/(?:^|[^a-zA-Z0-9_!#$%&*@＠]|RT:?)/");
		self::$regexen['atSigns'] = self::regexSupplant("/[@＠]/");
		self::$regexen['validMentionOrList'] = self::regexSupplant(
			'(#{validMentionPrecedingChars})' .  // $1: Preceding character
			'(#{atSigns})' .                     // $2: At mark
			'([a-zA-Z0-9_]{1,20})' .             // $3: Screen name
			'(\/[a-zA-Z][a-zA-Z0-9_\-]{0,24})?'  // $4: List (optional)
			, TwttrTxtRegex::MODIFIER_GREEDY);
		self::$regexen['validReply'] = self::regexSupplant("/^(?:#{spaces})*#{atSigns}([a-zA-Z0-9_]{1,20})/");
		self::$regexen['endMentionMatch'] = self::regexSupplant("/^(?:#{atSigns}|[#{latinAccentChars}]|:\/\/)/");

		// URL related regex collection
		self::$regexen['validUrlPrecedingChars'] = self::regexSupplant("/(?:[^A-Za-z0-9@＠$#＃#{invalid_chars_group}]|^)/");
		self::$regexen['invalidUrlWithoutProtocolPrecedingChars'] = self::regexSupplant("/[-_.\/]$/");
		self::$regexen['invalidDomainChars'] = self::regexSupplant("#{punct}#{spaces_group}#{invalid_chars_group}");
		self::$regexen['validDomainChars'] = self::regexSupplant("/[^#{invalidDomainChars}]/");
		self::$regexen['validSubdomain'] = self::regexSupplant("/(?:(?:#{validDomainChars}(?:[_-]|#{validDomainChars})*)?#{validDomainChars}\.)/");
		self::$regexen['validDomainName'] = self::regexSupplant("/(?:(?:#{validDomainChars}(?:-|#{validDomainChars})*)?#{validDomainChars}\.)/");
		self::$regexen['validGTLD'] = self::regexSupplant("/(?:(?:aero|asia|biz|cat|com|coop|edu|gov|info|int|jobs|mil|mobi|museum|name|net|org|pro|tel|travel|xxx)(?=[^0-9a-zA-Z]|$))/");
		self::$regexen['validCCTLD'] = self::regexSupplant(
		    "(?:(?:ac|ad|ae|af|ag|ai|al|am|an|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bm|bn|bo|br|bs|bt|bv|bw|by|bz|" .
		    "ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cs|cu|cv|cx|cy|cz|dd|de|dj|dk|dm|do|dz|ec|ee|eg|eh|er|es|et|eu|fi|fj|fk|fm|fo|fr|" .
		    "ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|" .
		    "ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|" .
		    "na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|" .
		    "sa|sb|sc|sd|se|sg|sh|si|sj|sk|sl|sm|sn|so|sr|ss|st|su|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tp|tr|tt|tv|tw|tz|" .
		    "ua|ug|uk|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|za|zm|zw)(?=[^0-9a-zA-Z]|$))");
		self::$regexen['validPunycode'] = self::regexSupplant("/(?:xn--[0-9a-z]+)/");
		self::$regexen['validDomain'] = self::regexSupplant("/(?:#{validSubdomain}*#{validDomainName}(?:#{validGTLD}|#{validCCTLD}|#{validPunycode}))/");
		self::$regexen['validAsciiDomain'] = self::regexSupplant("/(?:(?:[\-a-z0-9#{latinAccentChars}]+)\.)+(?:#{validGTLD}|#{validCCTLD}|#{validPunycode})/" . TwttrTxtRegex::MODIFIER_GREEDY . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['invalidShortDomain'] = self::regexSupplant("/^#{validDomainName}#{validCCTLD}$/");

		self::$regexen['validPortNumber'] = self::regexSupplant("/[0-9]+/");

		self::$regexen['validGeneralUrlPathChars'] = self::regexSupplant("/[a-z0-9!\*';:=\+,\.\$\/%#\[\]\-_~@|&#{latinAccentChars}]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		// Allow URL paths to contain balanced parens
		//  1. Used in Wikipedia URLs like /Primer_(film)
		//  2. Used in IIS sessions like /S(dfd346)/
		self::$regexen['validUrlBalancedParens'] = self::regexSupplant("/\(#{validGeneralUrlPathChars}+\)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		// Valid end-of-path chracters (so /foo. does not gobble the period).
		// 1. Allow =&# for empty URL parameters and other URL-join artifacts
		self::$regexen['validUrlPathEndingChars'] = self::regexSupplant("/[\+\-a-z0-9=_#\/#{latinAccentChars}]|(?:#{validUrlBalancedParens})/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		// Allow @ in a url, but only in the middle. Catch things like http://example.com/@user/
		self::$regexen['validUrlPath'] = self::regexSupplant('(?:' .
			'(?:' .
			  '#{validGeneralUrlPathChars}*' .
			    '(?:#{validUrlBalancedParens}#{validGeneralUrlPathChars}*)*' .
			    '#{validUrlPathEndingChars}' .
			  ')|(?:@#{validGeneralUrlPathChars}+\/)' .
			')', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validUrlQueryChars'] = self::regexSupplant("/[a-z0-9!?\*'@\(\);:&=\+\$\/%#\[\]\-_\.,~|]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validUrlQueryEndingChars'] = self::regexSupplant("/[a-z0-9_&=#\/]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['extractUrl'] = self::regexSupplant(
			'('                                                            . // $1 total match
			  '(#{validUrlPrecedingChars})'                                . // $2 Preceeding chracter
			  '('                                                          . // $3 URL
			    '(https?:\/\/)?'                                           . // $4 Protocol (optional)
			    '(#{validDomain})'                                         . // $5 Domain(s)
			    '(?::(#{validPortNumber}))?'                               . // $6 Port number (optional)
			    '(\/#{validUrlPath}*)?'                                    . // $7 URL Path
			    '(\?#{validUrlQueryChars}*#{validUrlQueryEndingChars})?'   . // $8 Query String
			  ')'                                                          .
			')'
			, TwttrTxtRegex::MODIFIER_CASEINSENSITIVE . TwttrTxtRegex::MODIFIER_GREEDY);

		self::$regexen['validTcoUrl'] = self::regexSupplant("/^https?:\/\/t\.co\/[a-z0-9]+/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['urlHasProtocol'] = self::regexSupplant("/^https?:\/\//" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['urlHasHttps'] = self::regexSupplant("/^https:\/\//" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// cashtag related regex
		self::$regexen['cashtag'] = self::regexSupplant("/[a-z]{1,6}(?:[._][a-z]{1,2})?/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validCashtag'] = self::regexSupplant('(^|#{spaces})(\\$)(#{cashtag})(?=$|\\s|[#{punct}])', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE . TwttrTxtRegex::MODIFIER_GREEDY);

		// These URL validation pattern strings are based on the ABNF from RFC 3986
		self::$regexen['validateUrlUnreserved'] = self::regexSupplant("/[a-z0-9\-._~]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlPctEncoded'] = self::regexSupplant("/(?:%[0-9a-f]{2})/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlSubDelims'] = self::regexSupplant("/[!$&'()*+,;=]/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlPchar'] = self::regexSupplant('(?:' .
			'#{validateUrlUnreserved}|' .
			'#{validateUrlPctEncoded}|' .
			'#{validateUrlSubDelims}|' .
			'[:|@]' .
			')', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlScheme'] = self::regexSupplant("/(?:[a-z][a-z0-9+\-.]*)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlUserinfo'] = self::regexSupplant('(?:' .
			'#{validateUrlUnreserved}|' .
			'#{validateUrlPctEncoded}|' .
			'#{validateUrlSubDelims}|' .
			':' .
			')*', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlDecOctet'] = self::regexSupplant("/(?:[0-9]|(?:[1-9][0-9])|(?:1[0-9]{2})|(?:2[0-4][0-9])|(?:25[0-5]))/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlIpv4'] = self::regexSupplant("/(?:#{validateUrlDecOctet}(?:\.#{validateUrlDecOctet}){3})/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// Punting on real IPv6 validation for now
		self::$regexen['validateUrlIpv6'] = self::regexSupplant("/(?:\[[a-f0-9:\.]+\])/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// Also punting on IPvFuture for now
		self::$regexen['validateUrlIp'] = self::regexSupplant('(?:' .
			'#{validateUrlIpv4}|' .
			'#{validateUrlIpv6}' .
			')', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// This is more strict than the rfc specifies
		self::$regexen['validateUrlSubDomainSegment'] = self::regexSupplant("/(?:[a-z0-9](?:[a-z0-9_\-]*[a-z0-9])?)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlDomainSegment'] = self::regexSupplant("/(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlDomainTld'] = self::regexSupplant("/(?:[a-z](?:[a-z0-9\-]*[a-z0-9])?)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlDomain'] = self::regexSupplant("/(?:(?:#{validateUrlSubDomainSegment]}\.)*(?:#{validateUrlDomainSegment]}\.)#{validateUrlDomainTld})/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlHost'] = self::regexSupplant('(?:' .
			'#{validateUrlIp}|' .
			'#{validateUrlDomain}' .
			')', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// Unencoded internationalized domains - this doesn't check for invalid UTF-8 sequences
		self::$regexen['validateUrlUnicodeSubDomainSegment'] = self::regexSupplant("/(?:(?:[a-z0-9]|[^\u0000-\u007f])(?:(?:[a-z0-9_\-]|[^\u0000-\u007f])*(?:[a-z0-9]|[^\u0000-\u007f]))?)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlUnicodeDomainSegment'] = self::regexSupplant("/(?:(?:[a-z0-9]|[^\u0000-\u007f])(?:(?:[a-z0-9\-]|[^\u0000-\u007f])*(?:[a-z0-9]|[^\u0000-\u007f]))?)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlUnicodeDomainTld'] = self::regexSupplant("/(?:(?:[a-z]|[^\u0000-\u007f])(?:(?:[a-z0-9\-]|[^\u0000-\u007f])*(?:[a-z0-9]|[^\u0000-\u007f]))?)/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlUnicodeDomain'] = self::regexSupplant("/(?:(?:#{validateUrlUnicodeSubDomainSegment}\.)*(?:#{validateUrlUnicodeDomainSegment}\.)#{validateUrlUnicodeDomainTld})/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlUnicodeHost'] = self::regexSupplant('(?:' .
			'#{validateUrlIp}|' .
			'#{validateUrlUnicodeDomain}' .
			')', TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlPort'] = self::regexSupplant("/[0-9]{1,5}/");

		self::$regexen['validateUrlUnicodeAuthority'] = self::regexSupplant(
			'(?:(#{validateUrlUserinfo})@)?'  . // $1 userinfo
			'(#{validateUrlUnicodeHost})'     . // $2 host
			'(?::(#{validateUrlPort}))?'        //$3 port
			, TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlAuthority'] = self::regexSupplant(
			'(?:(#{validateUrlUserinfo})@)?' . // $1 userinfo
			'(#{validateUrlHost})'           . // $2 host
			'(?::(#{validateUrlPort}))?'       // $3 port
			, TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		self::$regexen['validateUrlPath'] = self::regexSupplant("/(\/#{validateUrlPchar}*)*/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlQuery'] = self::regexSupplant("/(#{validateUrlPchar}|\/|\?)*/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);
		self::$regexen['validateUrlFragment'] = self::regexSupplant("/(#{validateUrlPchar}|\/|\?)*/" . TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// Modified version of RFC 3986 Appendix B
		self::$regexen['validateUrlUnencoded'] = self::regexSupplant(
			'^'                               . // Full URL
			'(?:'                             .
			  '([^:/?#]+):\/\/'               . // $1 Scheme
			')?'                              .
			'([^/?#]*)'                       . // $2 Authority
			'([^?#]*)'                        . // $3 Path
			'(?:'                             .
			  '\\?([^#]*)'                    . // $4 Query
			')?'                              .
			'(?:'                             .
			  '#(.*)'                         . // $5 Fragment
			')?$'
			, TwttrTxtRegex::MODIFIER_CASEINSENSITIVE);

		// transform regex objects to strings
		foreach(self::$regexen as $key => $regex) {
			self::$regexen[$key] = "" . $regex; // convert to strings
		}

		// flag as inited
		self::$_inited = true;
	}

	// Default CSS class for auto-linked lists (along with the url class)
	private static $_DEFAULT_LIST_CLASS = "tweet-url list-slug";
	// Default CSS class for auto-linked usernames (along with the url class)
	private static $_DEFAULT_USERNAME_CLASS = "tweet-url username";
	// Default CSS class for auto-linked hashtags (along with the url class)
	private static $_DEFAULT_HASHTAG_CLASS = "tweet-url hashtag";
	// Default CSS class for auto-linked cashtags (along with the url class)
	private static $_DEFAULT_CASHTAG_CLASS = "tweet-url cashtag";
	// Options which should not be passed as HTML attributes
	private static $_OPTIONS_NOT_ATTRIBUTES = array(
		'urlClass' => true, 'listClass' => true, 'usernameClass' => true, 'hashtagClass' => true, 'cashtagClass' => true,
		'usernameUrlBase' => true, 'listUrlBase' => true, 'hashtagUrlBase' => true, 'cashtagUrlBase' => true,
		'usernameUrlBlock' => true, 'listUrlBlock' => true, 'hashtagUrlBlock' => true, 'linkUrlBlock' => true,
		'usernameIncludeSymbol' => true, 'suppressLists' => true, 'suppressNoFollow' => true, 'targetBlank' => true,
		'suppressDataScreenName' => true, 'urlEntities' => true, 'symbolTag' => true, 'textWithSymbolTag' => true, 'urlTarget' => true,
		'invisibleTagAttrs' => true, 'linkAttributeBlock' => true, 'linkTextBlock' => true, 'htmlEscapeNonEntities' => true
	);

	private static $_BOOLEAN_ATTRIBUTES = array(
		'disabled' => true, 'readonly' => true, 'multiple' => true, 'checked' => true
	);

	/**
	 * Simple object cloning function for simple objects
	 *
	 * @param  mixed_var $obj
	 * @return mixed_var
	 */
	private static function _clone($obj) {
		if (is_object($obj)) {
			return clone $obj;
		}
		if (is_array($obj)) {
			$ret = array();
			foreach($obj as $key => $value) {
				$ret[$key] = self::_clone($obj);
			}
			return $ret;
		}
		return $obj;
	}

	public static function tagAttrs($attributes) {
		$htmlAttrs = "";
		foreach($attributes as $k => $v) {
			if (self::$_BOOLEAN_ATTRIBUTES[$k]) {
				$v = $v ? $k : null;
			}
			if ($v == null) continue;
			$htmlAttrs .= " " + self::htmlEscape($k) + "=\"" + self::htmlEscape($v) + "\"";
		}
		return $htmlAttrs;
	}

	public static function removeOverlappingEntities(&$entities) {
		usort($entities, function($a, $b) {
			return $a['indices'][0] - $b['indices'][0];
		});

		$prev = $entities[0];
		for ($i = 1; $i < count($entities); $i++) {
			if ($prev['indices'][1] > $entities[$i]['indices'][0]) {
				array_splice($entities, $i, 1);
				$i--;
			} else {
				$prev = $entities[$i];
			}
		}
	}

	public static function extractUrlsWithIndices($text, array $options = array()) {
		$options = self::_extend($options, array(
			'extractUrlsWithoutProtocol' => true
		));

		if (!$text || ($options['extractUrlsWithoutProtocol'] ? !preg_match("/\./", $text) : !preg_match("/:/", $text))) {
			return array();
		}

		$urls = array();

		$matches = array();
		preg_match_all(self::$regexen['extractUrl'], $text, $matches, PREG_OFFSET_CAPTURE);
		foreach($matches[0] as $i => $match) {
			$before = $matches[2][$i][0];
			$url = $matches[3][$i][0];
			$protocol = $matches[4][$i][0];
			$domain = $matches[5][$i][0];
			$path = $matches[7][$i][0];
			$startPosition = $match[1];
			$endPosition = $startPosition + strlen($match[0]);

			// if protocol is missing and domain contains non-ASCII characters,
			// extract ASCII-only domains.
			if (!$protocol) {
				if (!$options['extractUrlsWithoutProtocol']
				|| preg_match(self::$regexen['invalidUrlWithoutProtocolPrecedingChars'], $before)) {
					continue;
				}
				$lastUrl = null;
				$lastUrlInvalidMatch = false;
				$asciiEndPosition = 0;

				$domainMatches = array();
				preg_match_all(self::$regexen['validAsciiDomain'], $domain, $domainMatches);
				foreach($domainMatches[0] as $asciiDomain) {
					$asciiStartPosition = strpos($domain, $asciiDomaindomain, $asciiEndPosition);
					$asciiEndPosition = $asciiStartPosition + strlen($asciiDomain);
					$lastUrl = array(
						'url' => $asciiDomain,
						'indices' => array($startPosition + $asciiStartPosition, $startPosition + $asciiEndPosition)
					);
					if (!preg_match(self::$regexen['invalidShortDomain'], $asciiDomain)) {
						$urls[] = $lastUrl;
					}
				}

				// no ASCII-only domain found. Skip the entire URL.
				if ($lastUrl == null) {
					continue;
				}

				// lastUrl only contains domain. Need to add path and query if they exist.
				if ($path) {
					$lastUrl['url'] = str_replace($domain, $lastUrl['url'], $url);
					$lastUrl['indices'][1] = $endPosition;
					if ($lastUrlInvalidMatch) {
						$urls[] = $lastUrl;
					}
				}
			} else {
				$tcoMatches = array();
				// In the case of t.co URLs, don't allow additional path characters.
				if (preg_match(self::$regexen['validTcoUrl'], $url, $tcoMatches)) {
					$url = $tcoMatches[0];
					$endPosition = $startPosition + strlen($url);
				}
				$urls[] = array(
					'url' => $url,
					'indices' => array($startPosition, $endPosition)
				);
			}
		}

		return $urls;
	}

	public static function extractHashtags($text) {
		$hashtagsOnly = array();
		$hashtagsWithIndices = self::extractHashtagsWithIndices($text);

		foreach($hashtagsWithIndices as $hashtagWithIndices) {
			$hashtagsOnly[] = $hashtagWithIndices['hashtag'];
		}

		return $hashtagsOnly;
	}

	public static function extractHashtagsWithIndices($text, array $options = array()) {
		$options = self::_extend($options, array(
			'checkUrlOverlap' => true
		));

		if (!$text || !preg_match(self::$regexen['hashSigns'], $text)) {
			return array();
		}

		$tags = array();

		$matches = array();
		preg_match_all(self::$regexen['validHashtag'], $text, $matches, PREG_OFFSET_CAPTURE);

		foreach($matches[3] as $match) {
			$startPosition = $match[1];
			$after = substr($text, $startPosition);
			if (preg_match(self::$regexen['endHashtagMatch'], $after)) {
				continue;
			}
			$hashText = $match[0];
			$endPosition = $startPosition + strlen($hashText);
			$tags[] = array(
				'hashtag' => $hashText,
				'indices' => array(
					$startPosition,
					$endPosition
				)
			);
		}

		if ($options['checkUrlOverlap']) {
			// also extract URL entities
			$urls = self::extractUrlsWithIndices($text);
			if (count($urls) > 0) {
				$entities = array_merge($tags, $urls);
				// remove overlap
				self::removeOverlappingEntities($entities);
				// only push back hashtags
				$tags = array();
				foreach($entities as $entity) {
					if ($entity['hashtag']) {
						$tags[] = $entity;
					}
				}
			}
		}

		return $tags;
	}

}

TwttrTxt::init();
