<?php
namespace App\Support; use App\Models\Branch;
class BranchContext { private ?Branch $branch=null; public function set(Branch $branch):void{$this->branch=$branch;} public function branch():?Branch{return $this->branch;} public function id():?int{return $this->branch?->id;} }
