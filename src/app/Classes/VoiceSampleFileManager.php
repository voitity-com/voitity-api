<?php

namespace App\Classes;

use App\Exceptions\Voices\VoiceSampleFileManagerCouldNotProcessSample;
use getID3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VoiceSampleFileManager
{
    /**
     * Folder to store voice samples
     */
    const VOICE_SAMPLES_FOLDER = 'files/samples';

    private string $fileName;
    private int $fileDuration;

    /**
     * Instance of getID3 library for audio file analysis.
     *
     * @var getID3
     */
    private $getID3;

    public function __construct($getID3 = null)
    {
        $this->getID3 = $getID3 ?? new getID3;
        $this->fileName = '';
        $this->fileDuration = 0;
    }

    /**
     * Process the uploaded voice sample file. 
     * Store the file and extract duration.
     *
     * @param UploadedFile $file
     * @return boolean
     */
    public function processSampleFile(UploadedFile $file): bool
    {
        try {
            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $this->fileName = Str::uuid() . '.' . $extension;
            
            // Store file
            $path = Storage::putFileAs(
                self::VOICE_SAMPLES_FOLDER,
                $file,
                $this->fileName
            );

            if (!$path) {
                return false;
            }

            $audioInfo = $this->getID3->analyze($file->getPathname());

            if (isset($audioInfo['playtime_seconds'])) {
                $this->fileDuration = (int) ceil($audioInfo['playtime_seconds']);
            } else {
                $this->fileDuration = 0;
            }

            return true;

        } catch (\Exception $e) {
            throw new VoiceSampleFileManagerCouldNotProcessSample($e->getMessage());
        }
    }

    /**
     * Get the name of the processed audio file.
     *
     * @return string
     */
    public function getFileName(): string
    {
        return self::VOICE_SAMPLES_FOLDER . '/' . $this->fileName;
    }

    /**
     * Get the duration of the processed audio file.
     *
     * @return integer
     */
    public function getFileDuration(): int
    {
        return $this->fileDuration;
    }
}
