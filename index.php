<?php

/*
	Project Notes

	cURL OAuth Rest API
	http://stackoverflow.com/questions/1522869/how-do-i-use-oauth-with-php-and-curl
*/

/***********************************************

		MODELS

***********************************************/

class QuickConfiguration {

	private static $canConnect = false;

	private static $host;
	private static $user;
	private static $pass;
	private static $db;

	public static function connectDatabase($host = 'localhost', $user = 'root', $pass = 'root', $db = 'twitter_timelines') {


		self::$host = $host;
		self::$user = $user;
		self::$pass = $pass;
		self::$db = $db;

		try {
			$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
		} catch(Exception $e) {

			return false;
			error_log($e->getMessage());
		}

		self::$canConnect = true;
		return true;
	}

	public static function canConnect() {

		return self::$canConnect;
	}

	public static function getPDO() {

		return new PDO('mysql:host='.self::$host.';dbname='.self::$db, self::$user, self::$pass);
	}
}

class TwitterTimeline {

	private $oathValues = array();
	private $oathSecrets = array();

	private static $twitterUrlTimeline = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
	private $requestMethod = 'GET';

	private $urlParams = array();

	private $curl;

	public function __construct($oathValues) {

		// remove oauth_consumer_secret & oath_token_secret and track in separate array
		$this->oathSecrets['oath_token_secret'] = $oathValues['oath_token_secret'];
		$this->oathSecrets['oauth_consumer_secret'] = $oathValues['oauth_consumer_secret'];

		// remainder of values are used for headers and creating the signature
		unset($oathValues['oath_token_secret'], $oathValues['oauth_consumer_secret']);
		$this->oathValues = $oathValues;
	}

	/**
	 * Made in conjunction with the makeRandomString method. See makeRandomString() phpDoc for source
	 * @return string Unique string generated based on sha512 encoding of a random string
	 */
	private static function getNonce() {

		return hash('sha512', self::makeRandomString());
	}

	/**
	 * Source copied/pasted, from http://help.discretelogix.com/php/nonce-implementation-in-php.htm
	 * @param  integer $bits default 256bits
	 * @return string        A random string
	 */
	private static function makeRandomString($bits = 256) {
		$bytes = ceil($bits / 8); $return = ''; for ($i = 0; $i < $bytes; $i++) { $return .= chr(mt_rand(0, 255)); } return $return;
	}

	public function addUrlParam($urlKey, $urlParam) {

		$this->urlParams = array_merge(array($urlKey => $urlParam), $this->urlParams);
	}

	public function getTimelineJson($user='', $includeRetweets = false) {

		$this->addUrlParam('screen_name', $user);
		$this->addUrlParam('include_rts', $includeRetweets);

		$params = array_merge($this->urlParams, $this->oathValues);
		$curlOathValues = $this->oathSetup(self::$twitterUrlTimeline, $params);

		$this->setupCurl($curlOathValues);
		return $this->execCurl();
	}

	private function oathSetup($url, $params) {

		// final oath generated params
		$params['oauth_nonce'] = self::getNonce(); // unique string
		$params['oauth_timestamp'] = time();
		$params['oauth_signature'] = $this->getOathSignature($url, $params);

		return $params;
	}

	private function getOathSignature($url, $params) {

		$encodedPieces = array();

		foreach ($params as $sign_key => $sign_value) {

			$sign_key_encoded = rawurlencode($sign_key);
			$sign_value_encoded = rawurlencode($sign_value);

			$encodedPieces[$sign_key_encoded] = $sign_value_encoded;
		}

		ksort($encodedPieces);

		$paramString = '';
		$i = 0;
		$total_pieces = count($encodedPieces);

		foreach ($encodedPieces as $sortedKey => $sortedValue) {

			$paramString .= $sortedKey;
			$paramString .= '=';
			$paramString .= $sortedValue;

			$i++;
			if ($i < $total_pieces) {
				$paramString .= '&';
			}
		}

		$method = $this->requestMethod;
		$encodedUrl = rawurlencode(self::$twitterUrlTimeline);
		$encodedParamString = rawurlencode($paramString);

		$signatureBaseString = $method. '&' .$encodedUrl. '&' .$encodedParamString;

		$signingKey =	rawurlencode($this->oathSecrets['oauth_consumer_secret']).
						'&'.
						rawurlencode($this->oathSecrets['oath_token_secret']);

		return $oauth_signature = base64_encode(hash_hmac('sha1', $signatureBaseString, $signingKey, true));
	}

	private function setupCurl($oath) {

		$this->curl = curl_init($this->getUrlWithParams());
		$header[] = 'Content-Type: application/x-www-form-rawurlencoded';
		$header[] = 'Authorization: OAuth oauth_consumer_key="'. $oath['oauth_consumer_key'] .'", oauth_nonce="'. rawurlencode($oath['oauth_nonce']) .'", oauth_signature="'. rawurlencode($oath['oauth_signature']) .'", oauth_signature_method="'. rawurlencode($oath['oauth_signature_method']) .'", oauth_timestamp="'. $oath['oauth_timestamp'] .'", oauth_token="'. rawurlencode($oath['oauth_token']) .'", oauth_version="'. rawurlencode($oath['oauth_version']) .'"';

		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $header);
	}

	private function execCurl() {

		// prevent from response being outputted, capture as a string.
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
		$response = curl_exec($this->curl);

		return $response;
	}

	private function getUrlWithParams() {

		$paramString = '';
		foreach ($this->urlParams as $paramKey => $paramValue) {

			$paramString .= $paramKey. '=' .$paramValue.'&';
		}

		// Remove the last & at the end of the string, ie: 'param1=value1&param2=value2&'
		// Is cleaner without the final &, leaving: 'param1=value1&param2=value2', by:
		$paramString = substr($paramString, 0, strlen($paramString)-1);

		return self::$twitterUrlTimeline.'?'.$paramString;
	}
}

class TwitterTimelineHandler{

	private $tt;
	const TWEET_TBL = 'tweets';
	const MAP_TBL = 'tweets_map';

	public function __construct(TwitterTimeline $tt) {

		$this->tt = $tt;
	}

	public function getRecentTweets($user, $qty = 10, $cacheDuration = 10) {

		$cacheTimeInSeconds = $cacheDuration * 60;

		//	if user is no cached, and more than the cache time has elapsed since the user's tweets had been loaded
		//	then get latest
		if (	!$this->isUserCached($user) ||
				(	$this->tweetLastLoadedAt($user) !== false &&
					(time() - $this->tweetLastLoadedAt($user) > $cacheTimeInSeconds)
				)
		) {
			$this->updateTweetCache($user);
		}

		return $this->getCachedTweets($user, $qty);
	}

	public function updateTweetCache($user){

		error_log('Updating tweet cache');

		// If user is cached, only grab tweets since the last recorded tweet id.
		if ($this->isUserCached($user)) {

			$lastTweetId = $this->getUserLastTweetId($user);

			error_log("$user has existing tweets, getting tweets since tweet id: ". $lastTweetId);
			if ($lastTweetId !== false) {
				$this->tt->addUrlParam('since_id', $lastTweetId);
			}
		}

		$timelineJson = $this->tt->getTimelineJson($user);
		$tweets = json_decode($timelineJson, true);

		foreach ($tweets as $tweet) {

			/*
				What we need from each tweet
					- tweet_id		=> ['id']
					- user			=> (method param)
					- date			=> ['created_at']
					- tweet			=> json_encode($tweet)
			 */

			$tweetId = $tweet['id'];
			$date = $tweet['created_at'];
			$tweetJson = json_encode($tweet);

			$this->saveTweetToCache($tweetId, $user, $date, $tweetJson);
		}

		$this->updateTweetCacheMap($user);
	}

	private function updateTweetCacheMap($user){

		if ($this->isUserCached($user)) {

			$sql = "UPDATE ". self::MAP_TBL ." SET last_updated=NOW() WHERE user = :user;";
		} else {

			$sql = "INSERT INTO ". self::MAP_TBL ." (user, last_updated) VALUES (:user, NOW());";
		}

		if (QuickConfiguration::canConnect()) {

			$pdo = QuickConfiguration::getPDO();
			$sth = $pdo->prepare($sql);

			$result = $sth->execute(array('user' => $user));
			unset($pdo);

			return $result;
		}
		return false;
	}

	private function saveTweetToCache($tweetId, $user, $date, $tweetJson) {

		if (QuickConfiguration::canConnect()) {

			$sql = "INSERT INTO ". self::TWEET_TBL ."(tweet_id, user, date, tweet_json) VALUES (:tweet_id, :user, :date, :tweet_json);";

			$sqlFormattedDate = date("Y-m-d H:i:s", strtotime($date));

			$pdo = QuickConfiguration::getPDO();
			$sth = $pdo->prepare($sql);
			$result = $sth->execute(array('tweet_id' => $tweetId, 'user' => $user, 'date' => $sqlFormattedDate, 'tweet_json' => $tweetJson));

			if ($result) {

				return true;
			}

			unset($pdo);
		}

		return false;
	}

	public function getCachedTweets($user, $qty){

		error_log('Getting cached tweets');

		if (QuickConfiguration::canConnect()) {

			$sql = "SELECT tweet_json FROM ". self::TWEET_TBL ." WHERE user = :user ORDER BY tweet_id DESC LIMIT :qty";

			$pdo = QuickConfiguration::getPDO();
			$sth = $pdo->prepare($sql);
			$sth->bindValue(':user', $user);
			$sth->bindValue(':qty', (int) $qty, PDO::PARAM_INT);
			$sth->execute();

			$result = $sth->fetchAll(PDO::FETCH_COLUMN, 0);

			return $result;
			unset($pdo);
		}

		return false;
	}

	// return time since unix epoch
	public function tweetLastLoadedAt($user) {

		if (QuickConfiguration::canConnect()) {

			$sql = "SELECT UNIX_TIMESTAMP(last_updated) as 'last_updated' FROM ". self::MAP_TBL ." WHERE user = :user;";

			$pdo = QuickConfiguration::getPDO();
			$sth = $pdo->prepare($sql);
			$sth->execute(array('user' => $user));

			$result = $sth->fetch(PDO::FETCH_ASSOC);
			if ($result !== false) {

				unset($pdo);
				return $result['last_updated'];
			}

			unset($pdo);
		}

		return false;
	}

	private function getUserLastTweetId($user) {

		if (QuickConfiguration::canConnect()) {

			$sql = "SELECT tweet_id FROM ". self::TWEET_TBL ." WHERE user = :user ORDER BY tweet_id DESC LIMIT 1";

			$pdo = QuickConfiguration::getPDO();
			$sth = $pdo->prepare($sql);
			$sth->execute(array('user' => $user));
			$result = $sth->fetch(PDO::FETCH_ASSOC);

			if (isset($result['tweet_id'])){
				return $result['tweet_id'];
			}

			unset($pdo);
		}

		return false;
	}

	public function isUserCached($user) {

		if (QuickConfiguration::canConnect()) {

			$sql = "SELECT COUNT('X') as 'user_exists' FROM ". self::MAP_TBL ." WHERE user=:user";

			$pdo = QuickConfiguration::getPDO();
			$sth = $pdo->prepare($sql);

			// execute successfully
			if ($sth->execute(array('user' => $user))) {

				$result = $sth->fetch(PDO::FETCH_ASSOC);

				if($result['user_exists'] != 0){

					unset($pdo);
					return true;
				}
			}

			unset($pdo);
		}

		return false;
	}

}

/***********************************************

		CONTROLLER

***********************************************/

	// Set default user if one has not been set from form
	if (!isset($_REQUEST['user'])) {
		$user = 'snapshot_is';
	} else {
		$user = trim($_REQUEST['user']);
	}

	QuickConfiguration::connectDatabase();

	// bring in $oath - array of necessary values
	include('oath_settings.php');

	// $oath array is set within oath_settings.php
	$tt = new TwitterTimeline($oath);
	$tth = new TwitterTimelineHandler($tt);

	$message = "";
	if (empty($user)) {
		$user = 'snapshot_is';
		$message .= "<span class=\"notice\">The field was empty so we went ahead and found you some tweets from my friends at <a href=\"http://snapshot.is\">@snapshot_is</a></span>";
	}

	$jsonEncodedTweets = $tth->getRecentTweets($user);

	if (count($jsonEncodedTweets)) {

		/*
			Store an associative array of each tweet, cleaned from json.
			Results in a cleaner implementation for template, although theoretically
			 more computationally intensive to loop through twice instead of once.
		 */

		$tweets = array();
		foreach ($jsonEncodedTweets as $tweet) {

			$decodedTweet = json_decode($tweet, true);

			// Pulled from http://stackoverflow.com/questions/1798912/replace-any-urls-within-a-string-of-text-to-clickable-links-with-php
			$decodedTweet['text'] = preg_replace('"\b(http://\S+)"', '<a href="$1">$1</a>', $decodedTweet['text']);
			$tweets[] = $decodedTweet;
		}

		$message .= "<span class=\"timeline_intro\">Recent tweets from @$user:</span>";

	} else {

		$message .= "<span class=\"timeline_intro\">We're sorry, we we're able to find any tweets for $user</span>";
	}


/***********************************************

		VIEW

***********************************************/

// View requires:
// $tweets - array of tweets,
// $user - string of the current twitter user

?>
<!doctype html>
<html class="no-js" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Twitter Timeline</title>
    <link rel="stylesheet" href="css/foundation.css" />
    <script src="js/jquery.js"></script>
    <script src="js/modernizr.js"></script>

    <style>

		.twitter-timeline {

			padding-top: 20px;
		}

		.twitter-timeline input[type=text]{

			width: 50%;
		}

		.timeline_intro {

			font-size: 1.2em;
			display: block;
			font-weight: 400;
			margin-bottom: 20px;
			margin-top: 10px;
		}

		label[for=user] {

			font-size: 1.15em;
		}

		.user-at {

			color: grey;
			font-size: 0.9em;
		}

		input#user {

			display: inline-block;
			margin-left: 5px;
			margin-right: 15px;
		}

		button.submit {

			margin-bottom: 0;
			display: block;
		}

    </style>

    <script>
    	$(function(){
    		$('.clear-textfield').on('click', function(){
    			$('input#user').val('');
    		});
    	});
    </script>
  </head>
  <body>

	<div class="twitter-timeline row">
		<div class="large-12 columns">

			<h5>Go ahead and enter a twitter username to grab their ten latest tweets</h5>
			<h6>Note: Tweets are cached for 10 mintues before attempting to retrieve newer ones.</h6>

			<div class="panel radius">
				<form action="." method="GET">
					<label for="user">Username</label><span class='user-at'>@</span><input id="user" name="user" type="text" <?php echo (isset($user) && $user) ? "value=\"$user\"" : ""; ?>/>
					<span class="clear-textfield"><a href="javascript:void(0);">Clear</a></span>
					<button class="submit radius small" type="submit">Get tweets</button>
				</form>
			</div>

			<?php
				echo "<h5>$message</h5>";

				echo "<div class=\"tweets\">";
				if (isset($tweets) && is_array($tweets)) {
					foreach($tweets as $tweet) {
						echo "<div class=\"callout panel radius\">";
							echo "<h4>".$tweet['text']."</h4>";
							echo "<h6>".$tweet['created_at']."</h6>";
						echo "</div>";
					}
				}
				echo "</div>";
			?>
		</div>
	</div>

    <script src="js/foundation.min.js"></script>
    <script>
      $(document).foundation();
    </script>
  </body>
</html>