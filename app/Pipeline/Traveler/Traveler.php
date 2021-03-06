<?php

namespace App\Pipeline\Traveler;

use App\Models\Stream;
use App\Models\TravelerProgress;
use App\Pipeline\Pipe;
use App\Pipeline\PipeFactory;
use App\Pipeline\PipeIdentifier;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Laravel\Lumen\Routing\DispatchesJobs;

class Traveler implements ShouldQueue, SelfHandling
{
    use DispatchesJobs;

    /**
     * Bag that holds all the travelers items
     *
     * @var \App\Pipeline\Traveler\Bag
     */
    public $bag;

    /**
     * Array of all the pipes this traveler has been down
     *
     * @var \App\Pipeline\Pipe[]
     */
    protected $previousPipes = [];

    /**
     * Next pipe to process
     *
     * @var \App\Pipeline\Pipe
     */
    public $nextPipe;

    /**
     * Progress of the traveler
     *
     * @var \App\Models\TravelerProgress
     */
    public $progress;

    /**
     * Constructor
     *
     * @param Stream $stream Stream the traveler is on
     */
    public function __construct(Stream $stream)
    {
        $this->bag      = new Bag();
        $this->progress = new TravelerProgress();
        $this->progress->stream()->associate($stream);
    }

    /**
     * Sends the traveler down a pipe
     *
     * @param Pipe $pipe Pipe to send traveler down
     *
     * @return void
     */
    public function travel(Pipe $pipe)
    {
        $pipe->setStream($this->progress->stream);
        $this->progress->startOfPipe($this, $pipe);
        $models = $pipe->flowThrough($this->bag);

        $pipes = $this->getPipes($models);

        if (count($pipes) === 0) {
            // END OF THE LINE
            \Log::info('PIPELINE FINISHED');
            $this->progress->endOfPipeline($this);

            return;
        }

        $this->previousPipes[] = $pipe;
        $this->nextPipe = null;

        foreach ($pipes as $pipe) {
            $this->progress->endOfPipe($this, $pipe);
            $this->queueTravel($pipe);
        }
    }

    /**
     * Gets the pipe object from the model
     *
     * @param Model $pipeable Model to get pipe from
     *
     * @return \App\Pipeline\Pipe|null
     */
    public function getPipeFromPipeable(Model $pipeable)
    {
        $pipe = PipeFactory::make($pipeable);
        if ($pipe === null) {
            return;
        }

        return $pipe;
    }

    /**
     * Gets all the pipes from the given models
     *
     * @param \Illuminate\Database\Eloquent\Model|array $models Array of models
     *
     * @return \App\Pipeline\Pipe[]
     */
    public function getPipes($models)
    {
        if (!is_array($models)) {
            $models = [$models];
        }

        $pipes = [];

        foreach ($models as $model) {
            if ($model === null) {
                continue;
            }

            $pipe = $this->getPipeFromPipeable($model);

            if ($pipe === null) {
                continue;
            }

            $pipes[] = $pipe;
        }

        return $pipes;
    }

    /**
     * Queues the travel
     *
     * @param Pipe $nextPipe Next pipe to process when handled by queue
     *
     * @return void
     */
    public function queueTravel(Pipe $nextPipe)
    {
        $this->nextPipe = $nextPipe;

        $this->dispatch($this);
    }

    /**
     * Handles the job
     *
     * @return void
     */
    public function handle()
    {
        $this->travel($this->nextPipe);
    }

    /**
     * Prepare the instance for serialization.
     *
     * @return array All the properties to serialize
     */
    public function __sleep()
    {
        $this->bag->serialize();

        $this->previousPipes = array_map(
            function ($pipe) {
                return new PipeIdentifier($pipe);
            },
            $this->previousPipes
        );

        $this->nextPipe = new PipeIdentifier($this->nextPipe);

        $this->progress = new ModelIdentifier(
            get_class($this->progress),
            $this->progress->getKey()
        );

        return [
            'previousPipes',
            'nextPipe',
            'bag',
            'progress'
        ];
    }

    /**
     * Restore the instance after serialization.
     *
     * @return void
     */
    public function __wakeup()
    {
        $this->bag->unserialize();

        $this->previousPipes = array_map(
            function ($pipeIdentifier) {
                return PipeFactory::makeFromPipeIdentifier($pipeIdentifier);
            },
            $this->previousPipes
        );

        $this->nextPipe = PipeFactory::makeFromPipeIdentifier($this->nextPipe);

        $progressModelIdentifier = $this->progress;

        $this->progress = (new $progressModelIdentifier->class)
            ->findOrFail($progressModelIdentifier->id);
    }
}
