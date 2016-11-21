<?php

namespace Wikimedia\RemexHtml\TreeBuilder;
use Wikimedia\RemexHtml\Attributes;
use Wikimedia\RemexHtml\PlainAttributes;

class InBody extends InsertionMode {
	static private $headingNames = ['h1' => true, 'h2' => true, 'h3' => true, 'h4' => true,
		'h5' => true, 'h6' => true];

	public function characters( $text, $start, $length, $sourceStart, $sourceLength ) {
		$handleNonNull = function ( $text, $start, $length, $sourceStart, $sourceLength ) {
			if ( strspn( $text, "\t\n\f\r ", $start, $length ) !== $length ) {
				$this->builder->framesetOK = false;
			}
			$this->builder->insertCharacters( $text, $start, $length, $sourceStart, $sourceLength );
		};
		if ( !$this->builder->ignoreNulls ) {
			$this->stripNulls( $handleNonNull, $text, $start, $length, $sourceStart, $sourceLength );
		} else {
			$handleNonNull( $text, $start, $length, $sourceStart, $sourceLength );
		}
	}

	public function startTag( $name, Attributes $attrs, $selfClose, $sourceStart, $sourceLength ) {
		$mode = null;
		$tokenizerState = null;
		$isNewAFE = false;
		$builder = $this->builder;
		$stack = $builder->stack;
		$void = false;

		switch ( $name ) {
		case 'html':
			$builder->error( 'unexpected html tag', $sourceStart );
			if ( $stack->hasTemplate() ) {
				return;
			}
			if ( $stack->length() < 1 ) {
				return;
			}
			$builder->mergeAttributes( $stack->item( 0 ), $attrs, $sourceStart, $sourceLength );
			return;
		case 'base':
		case 'basefont':
		case 'bgsound':
		case 'link':
		case 'meta':
		case 'noframes':
		case 'script':
		case 'style':
		case 'template':
		case 'title':
			$this->dispatcher->inHead->startTag(
				$name, $attrs, $selfClose, $sourceStart, $sourceLength );
			return;
		case 'body':
			if ( $stack->length() < 2 || $stack->hasTemplate() ) {
				$builder->error( 'ignored unexpected body tag', $sourceStart );
				return;
			}
			$body = $stack->item( 1 );
			if ( $body->htmlName !== 'body' ) {
				$builder->error( 'ignored unexpected body tag', $sourceStart );
				return;
			}
			$builder->error( 'merged unexpected body tag', $sourceStart );
			$this->builder->framesetOK = false;
			$this->builder->mergeAttributes( $body, $attrs, $sourceStart, $sourceLength );
			return;
		case 'frameset':
			if ( !$builder->framesetOK || $stack->length() < 2 || $stack->hasTemplate() ) {
				$builder->error( 'ignored unexpected frameset tag', $sourceStart );
				return;
			}
			$body = $stack->item( 1 );
			if ( $body->htmlName !== 'body' ) {
				$builder->error( 'ignored unexpected frameset tag', $sourceStart );
				return;
			}
			$builder->error( 'unexpected frameset tag erases body contents', $sourceStart );
			$builder->handler->removeNode( $body, $sourceStart );
			// Pop all the nodes from the bottom of the stack of open elements,
			// from the current node up to, but not including, the root html element.
			$n = $stack->length();
			for ( $i = 0; $i < $n - 1; $i++ ) {
				$stack->pop();
			}
			$mode = Dispatcher::IN_FRAMESET;
			// Insert as normal
			break;
		case 'address':
		case 'article':
		case 'aside':
		case 'blockquote':
		case 'center':
		case 'details':
		case 'dialog':
		case 'dir':
		case 'div':
		case 'dl':
		case 'fieldset':
		case 'figcaption':
		case 'figure':
		case 'footer':
		case 'header':
		case 'hgroup':
		case 'main':
		case 'nav':
		case 'ol':
		case 'p':
		case 'section':
		case 'summary':
		case 'ul':
			$builder->closePInButtonScope( $sourceStart );
			break;
		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			$builder->closePInButtonScope( $sourceStart );
			if ( isset( self::$headingNames[$stack->current->htmlName] ) ) {
				$builder->error( 'invalid nested heading', $sourceStart );
				$builder->pop( $sourceStart, 0 );
			}
			break;
		case 'pre':
		case 'listing':
			$builder->closePInButtonScope( $sourceStart );
			$builder->framesetOK = false;
			break;
		case 'form':
			if ( $builder->formElement !== null && !$stack->hasTemplate() ) {
				$builder->error( 'invalid nested form', $sourceStart );
				return;
			}
			$builder->closePInButtonScope( $sourceStart );
			$elt = $builder->insertElement( 'form', $attrs, false,
				$sourceStart, $sourceLength );
			if ( !$this->stack->hasTemplate() ) {
				$builder->formElement = $elt;
			}
			return;
		case 'li':
			$builder->framesetOK = false;
			for ( $idx = $stack->length() - 1; $idx >= 0; $idx-- ) {
				$node = $stack->item( $idx );
				$htmlName = $node->htmlName;
				if ( $node->htmlName === 'li' ) {
					$builder->generateImpliedEndTagsWithError( 'li', $sourceStart );
					$builder->popAllUpToName( 'li', $sourceStart );
					break;
				}
				if ( isset( HTMLData::$special[$htmlName] )
					&& $htmlName !== 'address' && $htmlName !== 'div' && $htmlName !== 'p'
				) {
					break;
				}
			}
			$builder->closePInButtonScope( $sourceStart );
			break;
		case 'dd':
		case 'dt':
			$builder->framesetOK = false;
			for ( $idx = $stack->length() - 1; $idx >= 0; $idx-- ) {
				$node = $stack->item( $idx );
				$htmlName = $node->htmlName;
				if ( $htmlName === 'dd' || $htmlName === 'dt' ) {
					$builder->generateImpliedEndTagsWithError( $htmlName, $sourceStart );
					$builder->popAllUpToName( $htmlName, $sourceStart );
					break;
				}
				if ( isset( HTMLData::$special[$node] )
					&& $node !== 'address' && $node !== 'div' && $node !== 'p'
				) {
					break;
				}
			}
			$builder->closePInButtonScope( $sourceStart );
			break;
		case 'plaintext':
			$builder->closePInButtonScope( $sourceStart );
			$tokenizerState = Tokenizer::STATE_PLAINTEXT;
			break;
		case 'button':
			if ( $stack->isInScope( 'button' ) ) {
				$builder->error( 'invalid nested button tag', $sourceStart );
				$builder->generateImpliedEndTags( false, $sourceStart );
				$builder->popAllUpToName( 'button', $sourceStart );
			}
			$builder->reconstructAFE( $sourceStart );
			$builder->framesetOK = false;
			break;
		case 'a':
			$elt = $builder->afe->findElementByName( 'a' );
			if ( $elt !== null ) {
				$builder->error( 'invalid nested a tag', $sourceStart );
				$this->adoptionAgency( 'a', $sourceStart, 0 );
				$this->afe->remove( $elt );
				if ( $elt->stackIndex >= 0 ) {
					$stack->remove( $elt );
				}
			}
			$builder->reconstructAFE( $sourceStart );
			$isNewAFE = true;
			break;
		case 'b':
		case 'big':
		case 'code':
		case 'em':
		case 'font':
		case 'i':
		case 's':
		case 'small':
		case 'strike':
		case 'strong':
		case 'tt':
		case 'u':
			$builder->reconstructAFE( $sourceStart );
			$isNewAFE = true;
			break;
		case 'nobr':
			$builder->reconstructAFE( $sourceStart );
			if ( $stack->isInScope( 'nobr' ) ) {
				$builder->error( 'invalid nested nobr tag', $sourceStart );
				$this->adoptionAgency( 'nobr', $sourceStart, 0 );
				$builder->reconstructAFE( $sourceStart );
			}
			$isNewAFE = true;
			break;
		case 'applet':
		case 'marquee':
		case 'object':
			$builder->reconstructAFE( $sourceStart );
			$builder->afe->insertMarker();
			$builder->framesetOK = false;
			break;
		case 'table':
			if ( $builder->quirks !== TreeBuilder::QUIRKS ) {
				$builder->closePInButtonScope( $sourceStart );
			}
			$builder->framesetOK = false;
			$mode = Dispatcher::IN_TABLE;
			break;
		case 'area':
		case 'br':
		case 'embed':
		case 'img':
		case 'keygen':
		case 'wbr':
			$builder->reconstructAFE( $sourceStart );
			$dispatcher->ack = true;
			$void = true;
			$builder->framesetOK = false;
			break;
		case 'input':
			$builder->reconstructAFE( $sourceStart );
			$dispatcher->ack = true;
			$void = true;
			if ( !isset( $attribs['type'] ) || strcasecmp( $attribs['type'], 'hidden' ) !== 0 ) {
				$builder->framesetOK = false;
			}
			break;
		case 'param':
		case 'source':
		case 'track':
			$dispatcher->ack = true;
			$void = true;
			break;
		case 'hr':
			$builder->closePInButtonScope( $sourceStart );
			$dispatcher->ack = true;
			$void = true;
			$builder->framesetOK = false;
			break;
		case 'image':
			$builder->error( 'invalid "image" tag, assuming "img"', $sourceStart );
			$this->startTag( 'img', $attrs, $selfClose, $sourceStart, $sourceLength );
			return;
		case 'textarea':
			$tokenizerState = Tokenizer::STATE_RCDATA;
			$textMode = Dispatcher::TEXT;
			$builder->framesetOK = false;
			break;
		case 'xmp':
			$builder->closePInButtonScope( $sourceStart );
			$builder->reconstructAFE( $sourceStart );
			$builder->framesetOK = false;
			$tokenizerState = Tokenizer::STATE_RAWTEXT;
			$textMode = Dispatcher::TEXT;
			break;
		case 'iframe':
			$builder->framesetOK = false;
			$tokenizerState = Tokenizer::STATE_RAWTEXT;
			$textMode = Dispatcher::TEXT;
			break;
		case 'noscript':
			if ( !$builder->scriptingFlag ) {
				$builder->reconstructAFE( $sourceStart );
				break;
			}
			// fall through
		case 'noembed':
			$tokenizerState = Tokenizer::STATE_RAWTEXT;
			$textMode = Dispatcher::TEXT;
			break;
		case 'select':
			$builder->reconstructAFE( $sourceStart );
			$builder->framesetOK = false;
			if ( $dispatcher->isInTableMode() ) {
				$mode = Dispatcher::IN_SELECT_IN_TABLE;
			} else {
				$mode = Dispatcher::IN_SELECT;
			}
			break;
		case 'optgroup':
		case 'option':
			if ( $stack->current->htmlName === 'option' ) {
				$builder->pop( $sourceStart, 0 );
			}
			$builder->reconstructAFE( $sourceStart );
			break;
		case 'rb':
		case 'rp':
		case 'rtc':
			if ( $stack->isInScope( 'ruby' ) ) {
				$builder->generateImpliedEndTags( false, $sourceStart );
				if ( $stack->current->htmlName !== 'ruby'
				) {
					$builder->error( "<$name> is not a child of <ruby>", $sourceStart );
				}
			}
			break;
		case 'rt':
			if ( $stack->isInScope( 'ruby' ) ) {
				$builder->generateImpliedEndTags( 'rtc', $sourceStart );
				if ( !in_array( $stack->current->htmlName, [ 'ruby', 'rtc' ] ) ) {
					$builder->error( "<$name> is not a child of <ruby> or <rtc>", $sourceStart );
				}
			}
			break;
		case 'math':
			$builder->reconstructAFE( $sourceStart );
			$attrs = new ForeignAttributes( $attrs, 'math' );
			$dispatcher->ack = true;
			$builder->insertForeign( HTMLData::NS_MATHML, 'math', $attrs, $selfClose,
				$sourceStart, $sourceLength );
			return;
		case 'svg':
			$builder->reconstructAFE( $sourceStart );
			$attrs = new ForeignAttributes( $attrs, 'svg' );
			$dispatcher->ack = true;
			$builder->insertForeign( HTMLData::NS_SVG, 'svg', $attrs, $selfClose,
				$sourceStart, $sourceLength );
			return;
		case 'caption':
		case 'col':
		case 'colgroup':
		case 'frame':
		case 'head':
		case 'tbody':
		case 'td':
		case 'tfoot':
		case 'th':
		case 'thead':
		case 'tr':
			$builder->error( "$name is invalid in body mode" );
			return;
		case 'isindex':
			// TODO
			$builder->error( "$name is unimplemented" );
			// fall through
		default:
			$builder->reconstructAFE( $sourceStart );
		}

		// Generic element insertion, for all cases that didn't return above
		$element = $builder->insertElement( $name, $attrs, $void,
			$sourceStart, $sourceLength );
		if ( $isNewAFE ) {
			$builder->afe->push( $element );
		}

		if ( $tokenizerState !== null ) {
			$builder->tokenizer->switchState( $tokenizerState, $name );
		}
		if ( $mode !== null ) {
			$this->dispatcher->switchMode( $mode );
		} elseif ( $textMode !== null ) {
			$this->dispatcher->switchMode( $textMode, true );
		}
	}

	public function endTag( $name, $sourceStart, $sourceLength ) {
		$builder = $this->builder;
		$stack = $builder->stack;

		switch ( $name ) {
		case 'template':
			$this->dispatcher->inHead->endTag( $name, $sourceStart, $sourceLength );
			break;

		case 'body':
			if ( !$stack->isInScope( 'body' ) ) {
				$builder->error( 'end tag has no matching start tag in scope' );
				break;
			}
			$allowed = [
				'dd' => true,
				'dt' => true,
				'li' => true,
				'optgroup' => true,
				'option' => true,
				'p' => true,
				'rb' => true,
				'rp' => true,
				'rt' => true,
				'rtc' => true,
				'tbody' => true,
				'td' => true,
				'tfoot' => true,
				'th' => true,
				'thead' => true,
				'tr' => true,
				'body' => true,
				'html' => true,
			];
			$builder->checkUnclosed( $allowed );
			$this->dispatcher->switchMode( Dispatcher::AFTER_BODY );
			break;

		case 'address':
		case 'article':
		case 'aside':
		case 'blockquote':
		case 'button':
		case 'center':
		case 'details':
		case 'dialog':
		case 'dir':
		case 'div':
		case 'dl':
		case 'fieldset':
		case 'figcaption':
		case 'figure':
		case 'footer':
		case 'header':
		case 'hgroup':
		case 'listing':
		case 'main':
		case 'nav':
		case 'ol':
		case 'pre':
		case 'section':
		case 'summary':
		case 'ul':
			if ( !$stack->isInScope( $name ) ) {
				$builder->error( "unmatched end tag \"</$name>\"" );
				break;
			}
			$builder->generateImpliedEndTagsWithError( $name, $sourceStart );
			$builder->popAllUpToName( $name, $sourceStart, $sourceLength );
			break;

		case 'form':
			if ( !$stack->hasTemplate() ) {
				$node = $builder->formElement;
				$builder->formElement = null;
				if ( $node === null ) {
					$builder->error( "end tag \"</form>\" found when there is no open form element",
						$sourceStart );
					break;
				}
				if ( !$stack->isElementInScope( $node ) ) {
					$builder->error( "end tag \"</form>\" found when there is no form in scope",
						$sourceStart );
					break;
				}
				$builder->generateImpliedEndTags( false, $sourceStart );
				if ( $stack->current === $node ) {
					$builder->pop( $sourceStart, $sourceLength );
				} else {
					$builder->error( "end tag \"</form>\" found when there are tags open " .
						"which cannot be closed automatically", $sourceStart );
					$stack->remove( $node );
					$builder->handler->endTag( $node, $sourceStart, $sourceLength );
				}
			} else {
				if ( !$stack->isInScope( 'form' ) ) {
					$builder->error( "end tag \"</form>\" found when there is no form in scope",
						$sourceStart );
					break;
				}
				$builder->generateImpliedEndTagsWithError( 'form', $sourceStart );
				$builder->popAllUpToName( 'form' );
			}
			break;

		case 'p':
			if ( !$stack->isInButtonScope( 'p' ) ) {
				$builder->error( "end tag \"</p>\" found when there is no p in scope",
					$sourceStart );
				$builder->insertElement( 'p', new PlainAttributes, false, false, $sourceStart, 0 );
				$builder->pop( $sourceStart, $sourceLength );
				break;
			}
			$builder->generateImpliedEndTagsWithError( 'p', $sourceStart );
			$builder->popAllUpToName( 'p', $sourceStart, $sourceLength );
			break;

		case 'li':
			if ( !$stack->isInListScope( 'li' ) ) {
				$builder->error( "end tag \"</li>\" found when there is no li in scope",
					$sourceStart );
				break;
			}
			$builder->generateImpliedEndTagsWithError( 'li', $sourceStart );
			$builder->popAllUpToName( 'li', $sourceStart, $sourceLength );
			break;

		case 'dd':
		case 'dt':
			if ( !$stack->isInScope( $name ) ) {
				$builder->error( "end tag \"</$name>\" found when there is no $name in scope",
					$sourceStart );
				break;
			}
			$builder->generateImpliedEndTagsWithError( $name, $sourceStart );
			$builder->popAllUpToName( $name, $sourceStart, $sourceLength );
			break;

		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			if ( !$stack->isOneOfSetInScope( self::$headingNames ) ) {
				$builder->error( "end tag \"</$name>\" found when there is no heading tag in scope",
					$sourceStart );
				break;
			}
			$this->generateImpliedEndTags( false, $sourceStart );
			if ( $stack->current->htmlName !== $name ) {
				$builder->error( "end tag \"</$name>\" assumed to close non-matching heading tag",
					$sourceStart );
			}
			$builder->popAllUpToNames( self::$headingNames, $sourceStart, $sourceLength );
			break;

		case 'a':
		case 'b':
		case 'big':
		case 'code':
		case 'em':
		case 'font':
		case 'i':
		case 'nobr':
		case 's':
		case 'small':
		case 'strike':
		case 'strong':
		case 'tt':
		case 'u':
			$builder->adoptionAgency( $name, $sourceStart, $sourceLength );
			break;

		case 'applet':
		case 'marquee':
		case 'object':
			if ( !$stack->isInScope( $name ) ) {
				$builder->error( "end tag \"</$name>\" found when there is no $name in scope",
					$sourceStart );
				break;
			}
			$builder->generateImpliedEndTags( false, $sourceStart );
			if ( $stack->current->htmlName !== $name ) {
				$builder->error( "unmatched end tag \"</$name>\"", $sourceStart );
			}
			$builder->popAllUpToName( $name );
			$builder->afe->clearToMarker();
			break;

		case 'br':
			$builder->error( 'end tag </br> is invalid, assuming start tag', $sourceStart );
			$this->startTag( $name, new PlainAttributes, false, $sourceStart, $sourceLength );
			break;

		default:
			$builder->anyOtherEndTag( $name, $sourceStart, $sourceLength );
			break;
		}
	}

	public function endDocument( $pos ) {
		$this->builder->checkUnclosed( $allowed );
		if ( !$this->dispatcher->templateModeStack->isEmpty() ) {
			$this->dispatcher->inTemplate->endDocument( $pos );
		} else {
			$this->builder->stopParsing( $pos );
		}
	}
}