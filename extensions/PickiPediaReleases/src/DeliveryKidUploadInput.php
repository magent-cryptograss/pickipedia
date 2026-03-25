<?php
/**
 * Custom PageForms input type for uploading files to delivery-kid.
 *
 * Replaces MW's standard file upload (`input type=text|uploadable`) with
 * a delivery-kid dropzone. Used in the Blue Railroad Submission form so
 * that video bytes go to delivery-kid staging instead of the wiki server.
 *
 * The form field value is set to the delivery-kid draft_id after upload.
 *
 * Usage in wiki form definition:
 *   {{{field|video|input type=deliverykidupload}}}
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use PFFormInput;
use MediaWiki\Html\Html;

class DeliveryKidUploadInput extends PFFormInput {

	/**
	 * @return string
	 */
	public static function getName(): string {
		return 'deliverykidupload';
	}

	/**
	 * @return string[]
	 */
	public function getResourceModuleNames(): array {
		return [ 'ext.pickipediaReleases.deliveryKidInput' ];
	}

	/**
	 * @return array
	 */
	public static function getParameters(): array {
		$params = parent::getParameters();
		$params['accept'] = [
			'type' => 'string',
			'description' => 'File types to accept (MIME or extensions)',
		];
		return $params;
	}

	/**
	 * @return string
	 */
	public function getHtmlText(): string {
		$inputName = $this->mInputName;
		$currentValue = $this->mCurrentValue ?? '';
		$accept = $this->mOtherArgs['accept'] ?? '.mp4,.mkv,.webm,.mov,.avi,.m4v';
		$disabled = $this->mIsDisabled;
		$inputId = 'dki-' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $inputName );

		// Hidden field stores the draft_id — this is what gets saved to the wiki page
		$html = Html::element( 'input', [
			'type' => 'hidden',
			'name' => $inputName,
			'id' => $inputId . '-value',
			'value' => $currentValue,
		] );

		// Wrapper div for JS to find
		$html .= Html::openElement( 'div', [
			'class' => 'dki-wrapper',
			'id' => $inputId,
			'data-input-id' => $inputId . '-value',
			'data-accept' => $accept,
		] );

		if ( $currentValue ) {
			// Already uploaded — show the draft ID
			$html .= Html::rawElement( 'div', [ 'class' => 'dki-uploaded' ],
				Html::element( 'span', [ 'class' => 'dki-draft-id' ],
					'Draft: ' . $currentValue ) .
				( $disabled ? '' : Html::element( 'button', [
					'type' => 'button',
					'class' => 'dki-change-btn cdx-button',
				], 'Change' ) )
			);
		}

		if ( !$disabled ) {
			// Dropzone
			$html .= Html::openElement( 'div', [
				'class' => 'dki-dropzone uc-dropzone' . ( $currentValue ? ' dki-hidden' : '' ),
				'id' => $inputId . '-dropzone',
			] );
			$html .= Html::element( 'p', [], 'Drag file here or click to select' );
			$html .= Html::element( 'input', [
				'type' => 'file',
				'id' => $inputId . '-file',
				'accept' => $accept,
				'style' => 'display:none',
			] );
			$html .= Html::closeElement( 'div' );

			// Progress
			$html .= Html::rawElement( 'div', [
				'class' => 'dki-progress uc-progress-bar',
				'id' => $inputId . '-progress',
				'style' => 'display:none',
			], Html::element( 'div', [ 'class' => 'uc-progress-fill' ] ) );

			// Status
			$html .= Html::element( 'div', [
				'class' => 'dki-status uc-status',
				'id' => $inputId . '-status',
			] );
		}

		$html .= Html::closeElement( 'div' );

		return $html;
	}
}
