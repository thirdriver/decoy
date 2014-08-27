<?php namespace Bkwld\Decoy\Input\EncodingProviders;

// Dependencies
use Bkwld\Decoy\Exception;
use Bkwld\Decoy\Models\Encoding;
use Config;
use Services_Zencoder;
use Services_Zencoder_Exception;

/**
 * Encode videos with Zencoder
 */
class Zencoder extends EncodingProvider {

	/**
	 * Default outputs configuration
	 *
	 * @var array
	 */
	protected $defaults = array(

		// Normal HTML5 formats
		'mp4' => array(
			'format' => 'mp4',
			'h264_profile' => 'high',
		),
		'webm' => array(
			'format' => 'webm',
		),

		// For HLS encoding
		'hls-low' => array(
			'type' => 'segmented',
			'format' => 'mp4',
			'height' => 360,
			'h264_profile' => 'baseline',
		),
		'hls-med' => array(
			'type' => 'segmented',
			'format' => 'mp4',
			'h264_profile' => 'main',
		),
		'hls-high' => array(
			'type' => 'segmented',
			'format' => 'mp4',
			'height' => 720,
			'h264_profile' => 'high',
		),
	);

	/**
	 * The Encoding model instance that this encode is relateted to
	 *
	 * @var Bkwld\Decoy\Models\Encoding
	 */
	protected $model;

	/**
	 * Tell the service to encode an asset it's source
	 *
	 * @param string $source A full URL for the source asset
	 * @param Bkwld\Decoy\Models\Encoding $model 
	 * @return void 
	 */
	public function encode($source, Encoding $model) {
		$this->model = $model;

		// Tell the Zencoder SDK to create a job
		try {
			$outputs = $this->outputsConfig();
			$job = $this->sdk()->jobs->create(array(
				'input' => $source, 
				'output' => $this->outputsConfig($outputs),
			));

			// Store the response from the SDK
			$model->storeJob($job->id, $this->outputsToHash($job->outputs));

		// Report an error with the encode
		} catch(Services_Zencoder_Exception $e) {
			$model->status('error', implode(' ', $this->zencoderArray($e->getErrors())));
		} catch(Exception $e) {
			$model->status('error', $e->getMessage());
		}

	}

	/**
	 * Create the outputs config by merging the `outputs` config of the encode config
	 * file in with $this->defaults and then massaging into Zencoder's expected forat
	 * 
	 * @return array
	 */
	protected function outputsConfig() {
		return $this->addCommonProps($this->mergeConfigWithDefaults());
	}

	/**
	 * Update the config with properties that are common to all outputs
	 *
	 * @param array $config 
	 * @return array 
	 */
	protected function addCommonProps($outputs) {

		// Common settings
		$common = array(

			// Default height (960x540 at 16:9)
			'height' => 540,

			// Destination location as a directory
			'base_url' => Config::get('decoy::encode.destination'),

			// Make the outputs web readable on S3
			'public' => 1,

			// Slower encodes for better quality.  Their docs recommended this
			// which is why I'm using it instead of "1".
			'speed' => 2,

			// Register for notifications for when the conding is done
			'notifications' => array($this->notificationURL()),

		);

		// Apply common settings ontop of the passed config
		foreach($outputs as $label => &$config) {
			$common['label'] = $label;

			// Make the filename from the model id and the output label
			$common['filename'] = $this->model->getKey().'-'$label.'.'.$config['format'];

			// Do the merge
			$config = array_merge($common, $config);
		}

		// Strip the keys from the array at this point, Zencoder doesn't like them
		return array_values($outputs);
	}

	/**
	 * Massage the outputs from Zencoder into a key-val associative array
	 * 
	 * @param array $outputs
	 * @return array
	 */
	protected function outputsToHash($outputs) {
		return array_map(function($output) {
			return $output->url;
		}, $this->zencoderArray($outputs));
	}

	/**
	 * Handle notification requests from the SDK
	 *
	 * @param array $input Input::get()
	 * @return mixed Reponse to the API 
	 */
	public function handleNotification($input) {

		// Parse the input
		$job = $this->sdk()->notifications->parseIncoming()->job;

		// Find the encoding model instance.  If it's not found, then just
		// ignore it.  This can easily happen if someone replaces a video
		// while one is being uploaded.
		if (!$model = Encoding::where('job_id', '=', $job->id)->first()) return;

		// Loop through the jobs and look for error messages.  A job may recieve a
		// seperate notification for each output that has failed though the job
		// is still processessing.
		$errors = trim(implode(' ', array_map(function($output) {
			return isset($output->error_message) ? '(Output '.$output->label.') '.$output->error_message : null;
		}, $this->zencoderArray($job->outputs))));

		// If there were any messages, treat the job as errored.  This also tries
		// to fix an issue I saw where a final "error" notifcation wasn't fired even
		// though multiple jobs failed.
		$state = empty($errors) ? $job->state : 'failed';

		// Update the model
		switch($state) {
			
			// Simple passthru of status
			case 'processing';
			case 'cancelled';
				$model->status($job->state); 
				break;
			
			// Massage name
			case 'finished':
				$model->status('complete'); 
				break;
			
			// Find error messages on the output
			case 'failed':
				$model->status('error', $errors);
				break;
			
			// Default
			default:
				$model->status('error', 'Unkown Zencoder state: '.$job->state);
		}
	}

	/**
	 * Build an instance of the SDK
	 *
	 * @return Services_Zencoder
	 */
	public function sdk() {
		return new Services_Zencoder(Config::get('decoy::encode.api_key'));
	}

	/**
	 * Convert a Services_Zencoder_Object object to an array
	 *
	 * @param Services_Zencoder_Object|array $obj
	 * @return array 
	 */
	public function zencoderArray($obj) {
		if (is_array($obj)) return $obj;
		if (is_a($obj, 'Services_Zencoder_Object')) return get_object_vars($obj);
		throw new Exception('Unexpected object: '.get_class($obj));
	}

}