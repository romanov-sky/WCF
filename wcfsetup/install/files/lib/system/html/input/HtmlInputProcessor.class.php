<?php
namespace wcf\system\html\input;
use wcf\system\bbcode\HtmlBBCodeParser;
use wcf\system\html\input\filter\IHtmlInputFilter;
use wcf\system\html\input\filter\MessageHtmlInputFilter;
use wcf\system\html\input\node\HtmlInputNodeProcessor;
use wcf\system\html\AbstractHtmlProcessor;
use wcf\util\DOMUtil;
use wcf\util\StringUtil;

/**
 * Reads a HTML string, applies filters and parses all nodes including bbcodes.
 * 
 * @author      Alexander Ebert
 * @copyright   2001-2018 WoltLab GmbH
 * @license     GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package     WoltLabSuite\Core\System\Html\Input
 * @since       3.0
 */
class HtmlInputProcessor extends AbstractHtmlProcessor {
	/**
	 * list of embedded content grouped by type
	 * @var array
	 */
	protected $embeddedContent = [];
	
	/**
	 * @var	IHtmlInputFilter
	 */
	protected $htmlInputFilter;
	
	/**
	 * @var HtmlInputNodeProcessor
	 */
	protected $htmlInputNodeProcessor;
	
	/**
	 * Processes the input html string.
	 *
	 * @param       string          $html                   html string
	 * @param       string          $objectType             object type identifier
	 * @param       integer         $objectID               object id
	 * @param       boolean         $convertFromBBCode      interpret input as bbcode
	 */
	public function process($html, $objectType, $objectID = 0, $convertFromBBCode = false) {
		$this->reset();
		
		$this->setContext($objectType, $objectID);
		
		// enforce consistent newlines
		$html = StringUtil::trim(StringUtil::unifyNewlines($html));
		
		// check if this is true HTML or just a bbcode string
		if ($convertFromBBCode) {
			$html = $this->convertToHtml($html);
		}
		
		// transform bbcodes into metacode markers
		$html = HtmlBBCodeParser::getInstance()->parse($html);
		
		// filter HTML
		$html = $this->getHtmlInputFilter()->apply($html);
		
		// pre-parse HTML
		$this->getHtmlInputNodeProcessor()->load($this, $html);
		$this->getHtmlInputNodeProcessor()->process();
		$this->embeddedContent = $this->getHtmlInputNodeProcessor()->getEmbeddedContent();
	}
	
	/**
	 * Processes a HTML string to provide the general DOM API. This method
	 * does not perform any filtering or validation. You SHOULD NOT use this
	 * to deal with HTML that has not been filtered previously.
	 * 
	 * @param       string          $html   html string
	 */
	public function processIntermediate($html) {
		$this->getHtmlInputNodeProcessor()->load($this, $html);
	}
	
	/**
	 * Reprocesses a message by transforming the message into an editor-like
	 * state using plain bbcodes instead of metacode elements.
	 * 
	 * @param       string          $html           html string
	 * @param       string          $objectType     object type identifier
	 * @param       integer         $objectID       object id
	 * @since       3.1
	 */
	public function reprocess($html, $objectType, $objectID) {
		$this->processIntermediate($html);
		
		// revert embedded bbcodes for re-evaluation
		$metacodes = DOMUtil::getElements($this->getHtmlInputNodeProcessor()->getDocument(), 'woltlab-metacode');
		foreach ($metacodes as $metacode) {
			$name = $metacode->getAttribute('data-name');
			$attributes = $this->getHtmlInputNodeProcessor()->parseAttributes($metacode->getAttribute('data-attributes'));
			
			$bbcodeAttributes = '';
			foreach ($attributes as $attribute) {
				if (!empty($bbcodeAttributes)) $bbcodeAttributes .= ',';
				$bbcodeAttributes .= "'" . addcslashes($attribute, "'") . "'";
			}
			
			$text = $metacode->ownerDocument->createTextNode('[' . $name . (!empty($bbcodeAttributes) ? '=' . $bbcodeAttributes : '') . ']');
			$metacode->insertBefore($text, $metacode->firstChild);
			
			$text = $metacode->ownerDocument->createTextNode('[/' . $name . ']');
			$metacode->appendChild($text);
			
			DOMUtil::removeNode($metacode, true);
		}
		
		$this->process($this->getHtml(), $objectType, $objectID, false);
	}
	
	/**
	 * Processes only embedded content. This method should only be called when rebuilding
	 * data where only embedded content is relevant, but no actual parsing is required.
	 * 
	 * @param       string          $html           html string
	 * @param       string          $objectType     object type identifier
	 * @param       integer         $objectID       object id
	 * @throws      \UnexpectedValueException
	 */
	public function processEmbeddedContent($html, $objectType, $objectID) {
		if (!$objectID) {
			throw new \UnexpectedValueException("Object id parameter must be non-zero.");
		}
		
		$this->setContext($objectType, $objectID);
		
		$this->getHtmlInputNodeProcessor()->load($this, $html);
		$this->getHtmlInputNodeProcessor()->processEmbeddedContent();
		$this->embeddedContent = $this->getHtmlInputNodeProcessor()->getEmbeddedContent();
	}
	
	/**
	 * Checks the input html for disallowed bbcodes and returns any matches.
	 * 
	 * @return      string[]        list of matched disallowed bbcodes
	 */
	public function validate() {
		return $this->getHtmlInputNodeProcessor()->validate();
	}
	
	/**
	 * Enforces the maximum depth of nested quotes.
	 *
	 * @param	integer		$depth
	 */
	public function enforceQuoteDepth($depth) {
		$this->getHtmlInputNodeProcessor()->enforceQuoteDepth($depth);
	}
	
	/**
	 * Returns the parsed HTML ready to store.
	 * 
	 * @return      string  parsed html
	 */
	public function getHtml() {
		return $this->getHtmlInputNodeProcessor()->getHtml();
	}
	
	/**
	 * Returns the raw text content of current document.
	 * 
	 * @return      string          raw text content
	 */
	public function getTextContent() {
		return $this->getHtmlInputNodeProcessor()->getTextContent();
	}
	
	/**
	 * Returns true if the message appears to be empty.
	 * 
	 * @return      boolean         true if message appears to be empty
	 */
	public function appearsToBeEmpty() {
		return $this->getHtmlInputNodeProcessor()->appearsToBeEmpty();
	}
	
	/**
	 * Returns the all embedded content data.
	 *
	 * @return array
	 */
	public function getEmbeddedContent() {
		return $this->embeddedContent;
	}
	
	/**
	 * @return HtmlInputNodeProcessor
	 */
	public function getHtmlInputNodeProcessor() {
		if ($this->htmlInputNodeProcessor === null) {
			$this->htmlInputNodeProcessor = new HtmlInputNodeProcessor();
		}
		
		return $this->htmlInputNodeProcessor;
	}
	
	/**
	 * Sets the new object id.
	 * 
	 * @param       integer         $objectID       object id
	 */
	public function setObjectID($objectID) {
		$this->context['objectID'] = $objectID;
	}
	
	/**
	 * Resets internal states and discards references to objects.
	 */
	protected function reset() {
		$this->embeddedContent = [];
		$this->htmlInputNodeProcessor = null;
	}
	
	/**
	 * @return	IHtmlInputFilter
	 */
	protected function getHtmlInputFilter() {
		if ($this->htmlInputFilter === null) {
			$this->htmlInputFilter = new MessageHtmlInputFilter();
		}
		
		return $this->htmlInputFilter;
	}
	
	/**
	 * Converts bbcodes using newlines into valid HTML.
	 * 
	 * @param       string          $html           html string
	 * @return      string          parsed html string
	 */
	protected function convertToHtml($html) {
		$html = StringUtil::encodeHTML($html);
		$html = preg_replace('/\[attach=(\d+)\]/', "[attach=\\1,'none','2']", $html);
		$parts = preg_split('~(\n+)~', $html, null, PREG_SPLIT_DELIM_CAPTURE);
		
		$openParagraph = false;
		$html = '';
		for ($i = 0, $length = count($parts); $i < $length; $i++) {
			$part = $parts[$i];
			if (strpos($part, "\n") !== false) {
				$newlines = substr_count($part, "\n");
				if ($newlines === 1) {
					$html .= '<br>';
				}
				else {
					if ($openParagraph) {
						$html .= '</p>';
						$openParagraph = false;
					}
					
					// ignore one newline because a new paragraph with bbcodes is created
					// using two subsequent newlines
					$newlines--;
					if ($newlines === 0) {
						continue;
					}
					
					$html .= str_repeat('<p><br></p>', $newlines);
				}
			}
			else {
				if (!$openParagraph) {
					$html .= '<p>';
				}
				
				$html .= $part;
				$openParagraph = true;
			}
		}
		
		return $html . '</p>';
	}
}
