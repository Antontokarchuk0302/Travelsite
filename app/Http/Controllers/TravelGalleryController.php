<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TravelGallery;
use Intervention\Image\Facades\Image;
use App\Http\Requests\TravelGalleryRequest;
use App\Repositories\TravelGallery\TravelGalleryRepositoryInterface;
use App\Repositories\TravelPackage\TravelPackageRepositoryInterface;
use Illuminate\Support\Facades\Storage;

class TravelGalleryController extends Controller
{
    /**
     * The name of redirect route path
     *
     * @var string
     */
    private const REDIRECT_ROUTE = 'travel-galleries.index';

    /**
     * The name of maximum amount of travel galleries
     *
     * @var App\Services\TravelPackageService
     */
    private const MAXIMUM_AMOUNT_TRAVELGALLERIES = 5;

    /**
     * The name of repository instance
     *
     * @var App\Repositories\TravelPackage\TravelPackageRepository
     * @var App\Repositories\TravelGallery\TravelGalleryRepository
     */
    private $travelPackageRepository, $travelGalleryRepository;

    /**
     * Create a new sevice instance.
     *
     * @return void
     */
    public function __construct(TravelPackageRepositoryInterface $travelPackageRepositoryInterface, TravelGalleryRepositoryInterface $travelGalleryRepositoryInterface)
    {
        $this->travelPackageRepository = $travelPackageRepositoryInterface;
        $this->travelGalleryRepository = $travelGalleryRepositoryInterface;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $travelPackages = $this->travelPackageRepository->getAllTravelPackagesByKeywordOrStatus($request->keyword, '>')
            ->select(['title', 'slug'])
            ->withCountRelations(['travelGalleries'])
            ->latest()
            ->paginate(10);

        return view('pages.backend.travel-galleries.index', compact('travelPackages'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $travelPackages = $this->travelPackageRepository->getAllTravelPackagesByKeywordOrStatus('', '>')
            ->select(['id', 'title'])
            ->latest()
            ->get();

        return view('pages.backend.travel-galleries.create', compact('travelPackages'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function store(TravelGalleryRequest $request)
    {
        $travelPackageId = $request->validated()['travel_package'];

        $data = [
            'travel_package_id' => $travelPackageId,
            'uploaded_by' => auth()->id()
        ];

        return $this->checkProccess(
            self::REDIRECT_ROUTE,
            'status.create_new_travel_gallery',
            function () use ($travelPackageId, $request, $data) {
                if (!$this->checkAmountOfTravelGalleries($travelPackageId)) throw new \Exception(trans('The amount of travel galleries has exceeded capacity (max :items items)', ['items' => self::MAXIMUM_AMOUNT_TRAVELGALLERIES]));

                $data['name'] = $this->createImage($request);

                if (!$this->travelGalleryRepository->create($data)) {
                    $this->deleteImage($data['name']);
                    throw new \Exception(trans('status.failed_create_new_travel_gallery'));
                }
            }
        );
    }

    /**
     * Display the specified resource.
     *
     * @param string  $slug
     * @return \Illuminate\Http\Response
     */
    public function show(?string $slug)
    {
        $travelPackage = $this->travelPackageRepository->findOneTravelPackageByslug($slug)
            ->select(['id', 'title', 'slug'])
            ->firstOrNotFound();

        $title = $travelPackage->title;
        $travelGalleries = $travelPackage->travelGalleries;

        return view('pages.backend.travel-galleries.detail', compact('title', 'travelGalleries'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  string  $slug
     * @return \Illuminate\Http\Response
     */
    public function edit(TravelGallery $travelGallery)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\TravelGallery  $travelGallery
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, TravelGallery $travelGallery)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  string  $slug
     * @return mixed
     */
    public function destroy(?string $slug)
    {
        $travelGallery = $this->travelGalleryRepository->findOneTravelGalleryBySlug($slug)
            ->firstOrNotFound();

        return $this->checkProccess(
            self::REDIRECT_ROUTE,
            'status.delete_travel_gallery',
            function () use ($travelGallery) {
                $name = $travelGallery->name;

                if (!$travelGallery->delete()) throw new \Exception(trans('status.failed_delete_travel_gallery'));

                $this->deleteImage($name);
            }
        );
    }

    /**
     * Check one or more processes and catch them if fail
     *
     * @param  string $redirectRoute
     * @param  string $successMessage
     * @param  callable $action
     * @return \Illuminate\Http\Response
     */
    private function checkProccess(string $redirectRoute, string $succesMessage, callable $action)
    {
        try {
            $action();
        } catch (\Exception $e) {
            return redirect()->route($redirectRoute)
                ->with('failed', $e->getMessage());
        }

        return redirect()->route($redirectRoute)
            ->with('success', trans($succesMessage));
    }

    /**
     * check amount Of travel Galleries (maximum 5 items)
     *
     * @param  int $id
     * @param  int $max
     * @return bool
     */
    private function checkAmountOfTravelGalleries(int $id, ?int $max = self::MAXIMUM_AMOUNT_TRAVELGALLERIES)
    {
        $totalTravelGalleries = $this->travelPackageRepository->findByIdOrNotFound($id)
            ->travelGalleries()
            ->count();

        return $totalTravelGalleries < $max;
    }

    /**
     * create image(s) and thumbnail(s) for each travel gallery
     *
     * @param  \Illuminate\Http\Request $request
     * @return string
     */
    private function createImage(Request $request)
    {
        if ($request->hasFile('image')) {
            $fileName = $this->getFileName($request->file('image')->getClientOriginalName());

            $path = $this->checkDirectory($fileName);

            Image::make($request->file('image'))
                ->resize(1024, 683)
                ->save($path['pathImage']);

            Image::make($request->file('image'))
                ->resize(400, 267)
                ->save($path['pathThumbnail']);

            return $fileName;
        }
    }

    /**
     * create file name from request file
     *
     * @param  string $name
     * @return string
     */
    private function getFileName(string $name)
    {
        $fileName = explode('.', $name);
        $fileName = head($fileName) . rand(0, 100) . '.' . last($fileName);
        return $fileName;
    }

    /**
     * check existing directory file
     *
     * @param  string $fileName
     * @return array
     */
    private function checkDirectory(string $fileName)
    {
        $pathImage = storage_path("app/public/travel-galleries");
        if (!file_exists($pathImage)) mkdir($pathImage, 666, true);

        $pathThumbnail = storage_path("app/public/travel-galleries/thumbnails");
        if (!file_exists($pathThumbnail)) mkdir($pathThumbnail, 666, true);

        return [
            'pathImage' => $pathImage .= "/$fileName",
            'pathThumbnail' => $pathThumbnail .= "/$fileName"
        ];
    }

    /**
     * delete image file(s) from application directory
     *
     * @param  string $fileName
     * @return void
     */
    private function deleteImage(string $fileName)
    {
        Storage::disk('public')
            ->delete("travel-galleries/$fileName");
        Storage::disk('public')
            ->delete("travel-galleries/thumbnails/$fileName");
    }
}
