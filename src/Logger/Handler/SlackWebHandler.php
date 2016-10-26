<?php
namespace Logger\Handler;

use Monolog\Logger as MonoLogger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\Curl;

/**
 * Sends notifications through Slack Web Hook
 *
 * @author Dmitry Farafonov <dmitry.farafonov@gmail.com>
 */
class SlackWebHandler extends SocketHandler
{
	private static $iconMap;
	private static $colorMap;
    /**
     * Webhook URL
     * @var string
     */
    private $webhookUrl;

    /**
     * Slack channel (encoded ID or name)
     * @var string
     */
    private $channel;

    /**
     * Name of a bot
     * @var string
     */
    private $username;

    /**
     * Emoji icon name
     * @var string
     */
    private $iconEmoji;

    /**
     * Whether the message should be added to Slack as attachment (plain text otherwise)
     * @var bool
     */
    private $useAttachment;

    /**
     * Whether the the context/extra messages added to Slack as attachments are in a short style
     * @var bool
     */
    private $useShortAttachment;

    /**
     * Whether the attachment should include context and extra data
     * @var bool
     */
    private $includeContextAndExtra;

    /**
     * @var LineFormatter
     */
    private $lineFormatter;


    /**
     * @param  string                    $token                  Slack API token
     * @param  string                    $channel                Slack channel (encoded ID or name)
     * @param  string                    $username               Name of a bot
     * @param  bool                      $useAttachment          Whether the message should be added to Slack as attachment (plain text otherwise)
     * @param  string|null               $iconEmoji              The emoji name to use (or null)
     * @param  int                       $level                  The minimum logging level at which this handler will be triggered
     * @param  bool                      $bubble                 Whether the messages that are handled can bubble up the stack or not
     * @param  bool                      $useShortAttachment     Whether the the context/extra messages added to Slack as attachments are in a short style
     * @param  bool                      $includeContextAndExtra Whether the attachment should include context and extra data
     * @throws MissingExtensionException If no OpenSSL PHP extension configured
     */
    public function __construct($webhookUrl, $channel, $username = 'Monolog2', $useAttachment = true, $iconEmoji = null, $level = MonoLogger::CRITICAL, $bubble = true, $useShortAttachment = false, $includeContextAndExtra = false)
    {
    	$this->initIcons();
    	$this->initColors();

        parent::__construct($webhookUrl, $level, $bubble);

        $this->webhookUrl = $webhookUrl;
        $this->channel = $channel;
        $this->username = $username;
        $this->iconEmoji = trim($iconEmoji, ':');
        $this->useAttachment = $useAttachment;
        $this->useShortAttachment = $useShortAttachment;
        $this->includeContextAndExtra = $includeContextAndExtra;

        if ($this->includeContextAndExtra && $this->useShortAttachment) {
            $this->lineFormatter = new LineFormatter;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param  array  $record
     * @return string
     */
    protected function generateDataStream($record)
    {
        $content = $this->buildContent($record);
        return $content;

        //return $this->buildHeader($content) . $content;
    }

    /**
     * Builds the body of API call
     *
     * @param  array  $record
     * @return string
     */
    private function buildContent($record)
    {
        $dataArray = $this->prepareContentData($record);

        return $dataArray;
        //return http_build_query($dataArray);
    }

    /**
     * Prepares content data
     *
     * @param  array $record
     * @return array
     */
    protected function prepareContentData($record)
    {
        $dataArray = array(
            //'webhookUrl'       => $this->webhookUrl,
            'channel'     => $this->channel,
            'username'    => $this->username,
            'text'        => '',
        	'icon_emoji'  => self::$iconMap[$record['level']],
            'attachments' => array(),
        );
        if (array_key_exists('username', $record)) {
        	$dataArray['username'] = $record['username'];
        }

        if ($this->formatter) {
            $message = $this->formatter->format($record);
        } else {
            $message = $record['message'];
        }

        if ($this->useAttachment) {
            $attachment = array(
                'fallback' => $message,
                'color'    => $this->getAttachmentColor($record['level']),
                'fields'   => array(),
            );

            if ($this->useShortAttachment) {
                $attachment['title'] = $record['level_name'];
                $attachment['text'] = $message;
            } else {
                $attachment['title'] = 'Message';
                $attachment['text'] = $message;
                $attachment['fields'][] = array(
                    'title' => 'Level',
                    'value' => $record['level_name'],
                    'short' => true,
                );
            }

            if ($this->includeContextAndExtra) {
                if (!empty($record['extra'])) {
                    if ($this->useShortAttachment) {
                        $attachment['fields'][] = array(
                            'title' => "Extra",
                            'value' => $this->stringify($record['extra']),
                            'short' => $this->useShortAttachment,
                        );
                    } else {
                        // Add all extra fields as individual fields in attachment
                        foreach ($record['extra'] as $var => $val) {
                            $attachment['fields'][] = array(
                                'title' => $var,
                                'value' => $val,
                                'short' => $this->useShortAttachment,
                            );
                        }
                    }
                }

                if (!empty($record['context'])) {
                    if ($this->useShortAttachment) {
                        $attachment['fields'][] = array(
                            'title' => "Context",
                            'value' => $this->stringify($record['context']),
                            'short' => $this->useShortAttachment,
                        );
                    } else {
                        // Add all context fields as individual fields in attachment
                        foreach ($record['context'] as $var => $val) {
                            $attachment['fields'][] = array(
                                'title' => $var,
                                'value' => $val,
                                'short' => $this->useShortAttachment,
                            );
                        }
                    }
                }
            }

            //$dataArray['attachments'] = json_encode(array($attachment));
            $dataArray['attachments'] = (array($attachment));
        } else {
            $dataArray['text'] = $message;
        }
           // $dataArray['text'] = $message;

        if ($this->iconEmoji) {
            $dataArray['icon_emoji'] = ":{$this->iconEmoji}:";
        }

        return $dataArray;
    }

    public function write(array $record)
    {
    	$data = $this->generateDataStream($record);

    	$postString = "".json_encode($data);

    	$this->get($this->webhookUrl, $postString);
    }

    /**
     * Returned a Slack message attachment color associated with
     * provided level.
     *
     * @param  int    $level
     * @return string
     */
    protected function getAttachmentColor($level)
    {
    	return self::$colorMap[$level];
    }

    /**
     * Stringifies an array of key/value pairs to be used in attachment fields
     *
     * @param  array  $fields
     * @return string
     */
    protected function stringify($fields)
    {
        $string = '';
        foreach ($fields as $var => $val) {
            $string .= $var.': '.$this->lineFormatter->stringify($val)." | ";
        }

        $string = rtrim($string, " |");

        return $string;
    }


    protected function get($url, $postString) {
    	$ch = curl_init();
    	$header[] = "Cache-Control: max-age=0";
    	$header[] = "Connection: keep-alive";
    	$header[] = "Keep-Alive: 300";
    	$header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    	$header[] = "Accept-Language: en-us,en;q=0.5";
    	$header[] = "Pragma: "; // browsers keep this blank.
    	$header[] = "Content-Type: application/json";

    	//curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
    	curl_setopt($ch, CURLOPT_URL, $url);

    	curl_setopt($ch, CURLOPT_HEADER, false);

    	curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

    	curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
    	curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);

    	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    	//curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
    	//curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    	curl_setopt($ch, CURLOPT_POSTFIELDS, $postString);


//     	$proxy = Properties::get("curl.proxy");
//     	if ($proxy) {
//     		curl_setopt($ch, CURLOPT_PROXY, $proxy);
//     	}

    	$html = curl_exec($ch);
    	$this->info = curl_getinfo($ch);
    	$this->error = curl_error($ch);


    	curl_close($ch);
    	//echo "html: $html<br />";
    	return $html;
    }

    protected function initIcons() {
    	if (self::$iconMap != null) {
    		return self::$iconMap;
    	}
    	$iconMap = [];
    	$iconMap[MonoLogger::DEBUG] = ":pawprints:";
        $iconMap[MonoLogger::DEBUG] = ":beetle:";
        $iconMap[MonoLogger::INFO] = ":suspect:";
        $iconMap[MonoLogger::WARNING] = ":goberserk:";
        $iconMap[MonoLogger::ERROR] = ":feelsgood:";
        $iconMap[MonoLogger::CRITICAL] = ":finnadie:";
        self::$iconMap = $iconMap;
    }

    protected function initColors() {
    	if (self::$colorMap != null) {
    		return self::$colorMap;
    	}
    	$colorMap = [];
    	$colorMap[MonoLogger::DEBUG] = "#6f6d6d";
    	$colorMap[MonoLogger::DEBUG] = "#b5dae9";
    	$colorMap[MonoLogger::INFO] = "#5f9ea0";
    	$colorMap[MonoLogger::WARNING] = "#ff9122";
    	$colorMap[MonoLogger::ERROR] = "#ff4444";
    	$colorMap[MonoLogger::CRITICAL] = "#b03e3c";
    	self::$colorMap = $colorMap;
    }
}
