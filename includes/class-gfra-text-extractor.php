<?php

defined( 'ABSPATH' ) || exit;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

class GFRA_Text_Extractor {

	public static function extract( $file_path, $mime_type ) {
		try {
			if ( 'application/pdf' === $mime_type ) {
				return self::extract_pdf( $file_path );
			}
			if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type ) {
				return self::extract_docx( $file_path );
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	private static function extract_pdf( $file_path ) {
		$parser   = new PdfParser();
		$document = $parser->parseFile( $file_path );
		return $document->getText();
	}

	/**
	 * PhpWord has no single "get all text" call — walk sections/elements manually.
	 * Nested TextRun structures vary a bit by PhpWord version; verify against the
	 * actual pinned version before relying on this for anything but plain paragraphs.
	 */
	private static function extract_docx( $file_path ) {
		$phpWord = WordIOFactory::load( $file_path, 'Word2007' );
		$text    = '';

		foreach ( $phpWord->getSections() as $section ) {
			foreach ( $section->getElements() as $element ) {
				if ( method_exists( $element, 'getText' ) ) {
					$text .= $element->getText() . "\n";
				} elseif ( method_exists( $element, 'getElements' ) ) {
					foreach ( $element->getElements() as $child ) {
						if ( method_exists( $child, 'getText' ) ) {
							$text .= $child->getText() . ' ';
						}
					}
					$text .= "\n";
				}
			}
		}

		return $text;
	}
}
