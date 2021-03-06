<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * IMAP client class
 *
 * This library is a wrapper around the Imap library functions included in php. This class represents a single email
 * message as retrieved from the Imap. This is based on Robert Hafner's library.
 *
 * @package		Imap
 * @subpackage	Imap_Message
 * @author		Michael Lavers, Robert Hafner
 * @copyright	(c) 2009 Michael Lavers
 * @copyright	(c) 2009 Robert Hafner
 * @license		http://kohanaphp.com/license.html
 * @license		http://www.mozilla.org/MPL/
 */
class Imap_Message
{
	/**
	 * This is the connection/mailbox class that the email came from.
	 *
	 * @var Imap
	 */
	protected $imap_connection;

	/**
	 * This is the unique identifier for the message. This corresponds to the imap "uid", which we use instead of the
	 * sequence number.
	 *
	 * @var int
	 */
	protected $uid;

	/**
	 * This is a reference to the Imap stream generated by 'imap_open'.
	 *
	 * @var resource
	 */
	protected $imap_stream;

	/**
	 * This as an object which contains header information for the message.
	 *
	 * @var stdClass
	 */
	protected $headers;

	/**
	 * This is an object which contains various status messages and other information about the message.
	 *
	 * @var stdClass
	 */
	protected $message_overview;

	/**
	 * This is an object which contains information about the structure of the message body.
	 *
	 * @var stdClass
	 */
	protected $structure;


	/**
	 * This is an array with the index being imap flags and the value being a boolean specifying whether that flag is
	 * set or not.
	 *
	 * @var array
	 */
	protected $status = array();

	/**
	 * This is an array of the various imap flags that can be set.
	 *
	 * @var string
	 */
	static protected $flag_types = array('recent', 'flagged', 'answered', 'deleted', 'seen', 'draft');

	/**
	 * This holds the plantext email message.
	 *
	 * @var string
	 */
	protected $plaintext_message;

	/**
	 * This holds the html version of the email.
	 *
	 * @var string
	 */
	protected $html_message;

	/**
	 * This is the date the email was sent.
	 *
	 * @var int
	 */
	protected $date;

	/**
	 * This is the subject of the email.
	 *
	 * @var string
	 */
	protected $subject;

	/**
	 * This is the size of the email.
	 *
	 * @var int
	 */
	protected $size;

	/**
	 * This is an array containing information about the address the email came from.
	 *
	 * @var string
	 */
	protected $from;

	/**
	 * This is an array of arrays that contain information about the addresses the email was cc'd to.
	 *
	 * @var array
	 */
	protected $cc;

	/**
	 * This is an array of arrays that contain information about the addresses that should receive replies to the email.
	 *
	 * @var array
	 */
	protected $reply_to;

	/**
	 * This is an array of Imap_Attachments retrieved from the message.
	 *
	 * @var array
	 */
	protected $attachments = array();

	/**
	 * This value defines the encoding we want the email message to use.
	 *
	 * @var string
	 */
	static public $charset = 'UTF-8//TRANSLIT';

	/**
	 * This constructor takes in the uid for the message and the Imap class representing the mailbox the
	 * message should be opened from. This constructor should generally not be called directly, but rather retrieved
	 * through the apprioriate Imap functions.
	 *
	 * @param int $message_unique_id
	 * @param Imap $mailbox
	 */
	public function __construct($message_unique_id, Imap $mailbox)
	{
		$this->imap_connection = $mailbox;
		$this->uid = $message_unique_id;
		$this->imap_stream = $this->imap_connection->get_imap_stream();
		$this->load_message();
	}

	/**
	 * This function is called when the message class is loaded. It loads general information about the message from the
	 * imap server.
	 *
	 */
	protected function load_message()
	{
		/* First load the message overview information */

		$message_overview = $this->get_overview();

		$this->subject = $message_overview->subject;
		$this->date = strtotime($message_overview->date);
		$this->size = $message_overview->size;

		foreach (self::$flag_types as $flag)
			$this->status[$flag] = ($message_overview->$flag == 1);

		/* Next load in all of the header information */

		$headers = $this->get_headers();

		if (isset($headers->to))
			$this->to = $this->process_address_object($headers->to);

		if (isset($headers->cc))
			$this->cc = $this->process_address_object($headers->cc);

		$this->from = $this->process_address_object($headers->from);
		$this->reply_to = isset($headers->reply_to) ? $this->process_address_object($headers->reply_to) : $this->from;

		/* Finally load the structure itself */

		$structure = $this->get_structure();

		if ( ! isset($structure->parts))
		{
			// not multipart
			$this->process_structure($structure);
		}
		else
		{
			// multipart
			foreach ($structure->parts as $id => $part)
				$this->process_structure($part, $id + 1);
		}
	}

	/**
	 * This function returns an object containing information about the message. This output is similar to that over the
	 * imap_fetch_overview function, only instead of an array of message overviews only a single result is returned. The
	 * results are only retrieved from the server once unless passed TRUE as a parameter.
	 *
	 * @param bool $force_reload
	 * @return stdClass
	 */
	public function get_overview($force_reload = FALSE)
	{
		if ($force_reload or ! isset($this->message_overview))
		{
			// returns an array, and since we just want one message we can grab the only result
			$results = imap_fetch_overview($this->imap_stream, $this->uid, FT_UID);
			$this->message_overview = array_shift($results);
		}

		return $this->message_overview;
	}

	/**
	 * This function returns an object containing the headers of the message. This is done by taking the raw headers
	 * and running them through the imap_rfc822_parse_headers function. The results are only retrieved from the server
	 * once unless passed TRUE as a parameter.
	 *
	 * @param bool $force_reload
	 * @return stdClass
	 */
	public function get_headers($force_reload = FALSE)
	{
		if ($force_reload or ! isset($this->headers))
		{
			// raw headers (since imap_headerinfo doesn't use the unique id)
			$rawHeaders = imap_fetchheader($this->imap_stream, $this->uid, FT_UID);

			// convert raw header string into a usable object
			$header_object = imap_rfc822_parse_headers($rawHeaders);

			// to keep this object as close as possible to the original header object we add the udate property
			$header_object->udate = strtotime($header_object->date);

			$this->headers = $header_object;
		}

		return $this->headers;
	}

	/**
	 * This function returns an object containing the structure of the message body. This is the same object thats
	 * returned by imap_fetchstructure. The results are only retrieved from the server once unless passed TRUE as a
	 * parameter.
	 *
	 * @return stdClass
	 */
	public function get_structure($force_reload = FALSE)
	{
		if ($force_reload or ! isset($this->structure))
		{
			$this->structure = imap_fetchstructure($this->imap_stream, $this->uid, FT_UID);
		}

		return $this->structure;
	}


	/**
	 * This function returns the message body of the email. By default it returns the plaintext version. If a plaintext
	 * version is requested but not present, the html version is stripped of tags and returned. If the opposite occurs,
	 * the plaintext version is given some html formatting and returned. If neither are present the return value will be
	 * FALSE.
	 *
	 * @param bool $html Pass TRUE to receive an html response.
	 * @return string|bool Returns FALSE if no body is present.
	 */
	public function get_message_body($html = FALSE)
	{
		if ($html)
		{
			if ( ! isset($this->html_message) and isset($this->plaintext_message))
			{
				$output = nl2br($this->plaintext_message);
				return $output;
			}
			elseif (isset($this->html_message))
			{
				return $this->html_message;
			}
		}
		else
		{
			if ( ! isset($this->plaintext_message) and isset($this->html_message))
			{
				$output = strip_tags($this->html_message);
				return $output;
			}
			elseif (isset($this->plaintext_message))
			{
				return $this->plaintext_message;
			}
		}

		return FALSE;
	}

	/**
	 * This function returns either an array of email addresses and names or, optionally, a string that can be used in
	 * mail headers.
	 *
	 * @param string $type Should be 'to', 'cc', 'from', or 'reply-to'.
	 * @param bool $as_string
	 * @return array|string|bool
	 */
	public function get_addresses($type, $as_string = FALSE)
	{
		$address_types = array('to', 'cc', 'from', 'reply-to');

		if ( ! in_array($type, $address_types) or ! isset($this->$type) or count($this->$type) < 1)
			return FALSE;

		if ( ! $as_string)
		{
			if ($type == 'from')
				return $this->from[0];

			return $this->$type;
		}
		else
		{
			$output_string = '';
			foreach ($this->$type as $address)
			{
				if (isset($set))
					$output_string .= ', ';
				if ( ! isset($set))
					$set = TRUE;

				$output_string .= isset($address['name']) ?
								  $address['name'] . ' <' . $address['address'] . '>'
								: $address['address'];
			}

			return $output_string;
		}
	}

	/**
	 * This function returns the date, as a timestamp, of when the email was sent.
	 *
	 * @return int
	 */
	public function get_date()
	{
		return isset($this->date) ? $this->date : FALSE;
	}

	/**
	 * This returns the subject of the message.
	 *
	 * @return string
	 */
	public function get_subject()
	{
		return $this->subject;
	}

	/**
	 * This function marks a message for deletion. It is important to note that the message will not be deleted form the
	 * mailbox until the Imap->expunge it run.
	 *
	 * @return bool
	 */
	public function delete()
	{
		return imap_delete($this->imap_stream, $this->uid, FT_UID);
	}

	/**
	 * This function returns Imap this message came from.
	 *
	 * @return Imap
	 */
	public function get_imap_box()
	{
		return $this->imap_connection;
	}

	/**
	 * This function takes in a structure and identifier and processes that part of the message. If that portion of the
	 * message has its own subparts, those are recursively processed using this function.
	 *
	 * @param stdClass $structure
	 * @param string $part_identifier
	 * @todoa process attachments.
	 */
	protected function process_structure($structure, $part_identifier = NULL)
	{
		$parameters = self::get_parameters_from_structure($structure);

		if (isset($parameters['name']) or isset($parameters['filename']))
		{
			$attachment = new Imap_Attachment($this, $structure, $part_identifier);
			$this->attachments[] = $attachment;
		}
		elseif($structure->type == 0 or $structure->type == 1)
		{
			$message_body = isset($part_identifier) ?
							  imap_fetchbody($this->imap_stream, $this->uid, $part_identifier, FT_UID)
							: imap_body($this->imap_stream, $this->uid, FT_UID);

			$message_body = self::decode($message_body, $structure->encoding);

			if (isset($parameters['charset']) AND $parameters['charset'] !== self::$charset)
			{
				$current_charset = strtolower(self::$charset);

				if (strtolower($parameters['charset']) == 'us-ascii'
					AND ! (strpos($current_charset, 'utf-8') === 0
						OR strpos($current_charset, 'iso-8859-1') === 0)
					)
				{
					$message_body = iconv($parameters['charset'], self::$charset, $message_body);
				}
			}

			if (strtolower($structure->subtype) == 'plain' or $structure->type == 1)
			{
				if (isset($this->plaintext_message))
				{
					$this->plaintext_message .= PHP_EOL . PHP_EOL;
				}
				else
				{
					$this->plaintext_message = '';
				}

				$this->plaintext_message .= trim($message_body);
			}
			else
			{
				if (isset($this->html_message))
				{
					$this->html_message .= '<br><br>';
				}
				else
				{
					$this->html_message = '';
				}

				$this->html_message .= $message_body;
			}
		}

		if (isset($structure->parts))
		{
			// multipart: iterate through each part
			foreach ($structure->parts as $part_index => $part)
			{
				$part_id = $part_index + 1;

				if (isset($part_identifier))
					$part_id = $part_identifier . '.' . $part_id;

				$this->process_structure($part, $part_id);
			}
		}
	}

	/**
	 * This function takes in the message data and encoding type and returns the decoded data.
	 *
	 * @param string $data
	 * @param int|string $encoding
	 * @return string
	 */
	static public function decode($data, $encoding)
	{
		if ( ! is_numeric($encoding))
			$encoding = strtolower($encoding);

		switch ($encoding)
		{
			case 'quoted-printable':
			case 4:
				return quoted_printable_decode($data);

			case 'base64':
			case 3:
				return base64_decode($data);

			default:
				return $data;
		}
	}

	/**
	 * This function returns the body type that an imap integer maps to.
	 *
	 * @param int $id
	 * @return string
	 */
	static public function type_id_to_string($id)
	{
		switch($id)
		{
			case 0:
				return 'text';

			case 1:
				return 'multipart';

			case 2:
				return 'message';

			case 3:
				return 'application';

			case 4:
				return 'audio';

			case 5:
				return 'image';

			case 6:
				return 'video';

			default:
			case 7:
				return 'other';
		}
	}

	/**
	 * Takes in a section structure and returns its parameters as an associative array.
	 *
	 * @param stdClass $structure
	 * @return array
	 */
	static function get_parameters_from_structure($structure)
	{
		$parameters = array();
		if (isset($structure->parameters))
			foreach ($structure->parameters as $parameter)
				$parameters[strtolower($parameter->attribute)] = $parameter->value;

		if (isset($structure->dparameters))
			foreach ($structure->dparameters as $parameter)
				$parameters[strtolower($parameter->attribute)] = $parameter->value;

		return $parameters;
	}

	/**
	 * This function takes in an array of the address objects generated by the message headers and turns them into an
	 * associative array.
	 *
	 * @param array $addresses
	 * @return array
	 */
	protected function process_address_object($addresses)
	{
		$output_addresses = array();
		if (is_array($addresses))
			foreach ($addresses as $address)
		{
			$current_address = array();
			$current_address['address'] = $address->mailbox . '@' . $address->host;
			if (isset($address->personal))
				$current_address['name'] = $address->personal;
			$output_addresses[] = $current_address;
		}

		return $output_addresses;
	}

	/**
	 * This function returns the unique id that identifies the message on the server.
	 *
	 * @return int
	 */
	public function get_uid()
	{
		return $this->uid;
	}

	/**
	 * This function returns the attachments a message contains. If a filename is passed then just that Imap_Attachment
	 * is returned, unless
	 *
	 * @param NULL|string $filename
	 * @return array|bool|Imap_Attachments
	 */
	public function get_attachments($filename = NULL)
	{
		if ( ! isset($this->attachments) or count($this->attachments) < 1)
			return FALSE;

		if ( ! isset($filename))
			return $this->attachments;

		$results = array();
		foreach ($this->attachments as $attachment)
		{
			if ($attachment->get_file_name() == $filename)
				$results[] = $attachment;
		}

		switch (count($results))
		{
			case 0:
				return FALSE;

			case 1:
				return array_shift($results);

			default:
				return $results;
				break;
		}
	}

	/**
	 * This function checks to see if an imap flag is set on the email message.
	 *
	 * @param string $flag Recent, Flagged, Answered, Deleted, Seen, Draft
	 * @return bool
	 */
	public function check_flag($flag = 'flagged')
	{
		return (isset($this->status[$flag]) and $this->status[$flag] == TRUE);
	}

	/**
	 * This function is used to enable or disable a flag on the imap message.
	 *
	 * @param string $flag Flagged, Answered, Deleted, Seen, Draft
	 * @param bool $enable
	 * @return bool
	 */
	public function set_flag($flag, $enable = TRUE)
	{
		if ( ! in_array($flag, self::$flag_types) or $flag == 'recent')
			throw new Imap_Exception('Unable to set invalid flag "' . $flag . '"');

		$flag = '\\' . ucfirst($flag);

		if ($enable)
		{
			return imap_setflag_full($this->imap_stream, $this->uid, $flag, ST_UID);
		}
		else
		{
			return imap_clearflag_full($this->imap_stream, $this->uid, $flag, ST_UID);
		}
	}

}
