<?php

namespace Prettus\Repository\Contracts;

use Illuminate\Http\Request;

interface ResourceableInterface
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index();

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request   $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request);


    /**
     * Display the specified resource.
     *
     * @param  int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($id);


    /**
     * Update the specified resource in storage.
     *
     * @param  Request   $request
     * @param  string   $id
     *
     * @return Response
     */
    public function update(Request $request, $id);


    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($id);
}