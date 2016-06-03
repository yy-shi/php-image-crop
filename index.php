<?php

/**
 * 图片裁切与压缩
 * @license shiyongyong
 */

$url = $_GET['path'];
$process = analysisUrl($url);
compressImage($process);

/**
 * 解析url路径
 * resize  宽  高 路径
 * crop    宽 高  路径
 * fill  （颜色）宽 高 路径
 * nothing  路径
 * @param mixed $url
 * @access public
 * @return void
 */
function analysisUrl($url){
	if(preg_match('/\/(resize|crop|fill)(\/.*)?\/(\d*)x(\d*)\/(.*)/', $url,$matched)){
		return [
			'proc'      => $matched[1],
			'width'     => $matched[3],
			'height'    => $matched[4],
			'path'      => $matched[5],
			'color'    => ltrim($matched[2],'/'),
			'uri'       => $matched[0],
		];
	}else{
			return [
			'proc'      => "nothing",
			'width'     => 0,
			'height'    => 0,
			'path'      => $url,
			'color'    => "",
			'uri'       => "",
		];

	}
	return false;
}

/**
 * 根据解析的结果处理图片
 * 并在图片尺寸以及颜色处理结束后
 * 删除profile
 * 删除property
 * Imagick::stripImage — 去掉图片的所有配置和设置
 * 设置图片质量 85
 * 设置图片格式
 * 减少图像中的散斑噪声,同时保留原始图像的边缘。
 * @param mixed $data
 * @access public
 * @return void
 */
function compressImage($data){
	$sourceDir="/home/vagrant/data/image";
	$file = $sourceDir.'/'.$data['path'];

	$image = new Imagick($file);

	$path = pathinfo($file);
	$types = array(
		'jpg'=> "image/jpeg",
		'jpeg'=> "image/jpeg",
		'png'=> "image/png",
		'gif'=> "image/gif",
	);
	$ext = $path['extension'];
	$ext = isset($types[$ext])?$ext:'jpg';
	$procMethod = 'proc'.ucfirst($data['proc']);
	if(function_exists($procMethod)){
		$image = $procMethod($image,$data);
	}

	$profiles = $image->getImageProfiles("*", true);
	foreach($profiles as $key=>$v){
		$image->removeImageProfile($key);
	}
	$properties = $image->getImageProperties("*",true);
	foreach($properties as $key=>$v){
		$image->deleteImageProperty($key);
	}
	if($ext=="jpg" || $ext =="jpeg"){
		$image->setImageCompression(Imagick::COMPRESSION_JPEG);
		$image->setImageCompressionQuality(85);
	}
	if($ext == "png"){
		$flag = $image->getImageAlphaChannel();
		// 如果png背景非透明，则进行压缩
		if(imagick::ALPHACHANNEL_UNDEFINED == $flag||imagick::ALPHACHANNEL_DEACTIVATE == $flag) {
			$image->setImageType(imagick::IMGTYPE_PALETTE);
		}
	}
	$image->setImageFormat($ext);
	$image->stripImage();
	$image->despeckleImage();
//输出webp格式图片, 图片体积较小(减少45%左右)，但是右键另存为之后无法打开，web端只有chrome支持，
//移动端app需要第三方扩展，图片解码时间4倍左右（还是毫秒级）。原生不支持
//Header("Content-type: image/webp");
//imagewebp(imagecreatefromstring($image->getImageBlob()));
//exit;
	Header("Content-type: ".$types[$ext]);
	$ot = fopen("php://output","w");
	$image->writeImageFile($ot);
	fclose($ot);
	$image->destroy();
}

/**
 * resize
 * 尺寸不超过原图，不进行放大
 * @param mixed $image
 * @param mixed $data
 * @param mixed $noZoom
 * @access public
 * @return void
 */
function procResize( $image,$data, $noZoom=true){
	$source = $image->getImageGeometry();
	$width = $data['width'];
	$height = $data['height'];
	if($noZoom){
		$width = $width>$source['width']?$source['width']:$width;
		$height = $height>$source['height']?$source['height']:$height;
	}
	$image->resizeImage($width,$height,Imagick::FILTER_UNDEFINED,1,true);
	//$image->resizeImage($width,$height,Imagick::FILTER_LANCZOS,1,true);
	//$image->resizeImage($width,$height,Imagick::FILTER_BESSEL,1,true);
	return $image;
}
/**
 * 裁切。宽高不超过原图，
 * 超过后，按比例缩小数据，再裁切
 * @param ImageInterface $image
 * @param unknown $data
 * @return ImageInterface
 */
 function procCrop($image,$data){
	 $source = $image->getImageGeometry();
	 $wRatio = $data['width']/$source['width'];
	 $hRatio = $data['height']/$source['height'];
	 $ratio = max($wRatio, $hRatio);
	 if($ratio<= 1){
		 if($hRatio>$wRatio){
			 $x = round(($source['width'] - $data['width'])/2);
			 $image->cropImage($data['width'], $data['height'], $x, 0);
		 }else{
			 $image->cropImage($data['width'], $data['height'],0,0);
		 }
		 $image->setImagePage(0,0,0,0);
	 }else{
		 $wRatio = $data['width']/$source['width'];
		 $hRatio = $data['height']/$source['height'];
		 $ratio = max($wRatio, $hRatio);
		 $data['height'] = $data['height']/$ratio;
		 $data['width'] = $data['width']/$ratio;
		 return procCrop($image, $data);
	 }
	 return $image;
}



/**
 * 最大宽度3000 最大高度3000
 * 先Resize大小,按比例缩小（不放大），所以实际fill的尺寸也会变小
 * 然后空白部分用
 * @param ImageInterface $image
 * @param unknown $data
 * @return \Imagine\Imagick\ImageInterface
 */

function procFill($image,$data){
	$data['width'] = $data['width']>3000?3000:$data['width'];
	$data['height'] = $data['height']>3000?3000:$data['height'];
	$image = procResize($image,$data);
	$source = $image->getImageGeometry();
	$wRatio = $data['width']/$source['width'];
	$hRatio = $data['height']/$source['height'];
	$x= $y =0;
	if($wRatio > $hRatio){
		$width = intval($data['width']/$hRatio);
		$height = $source['height'];
		$x = ($width - $source['width'])/2;
	}else{
		$height = intval($data['height']/$wRatio);
		$width = $source['width'];
		$y = ($height - $source['height'])/2;
	}
	$color = empty($data['color'])?"FFFFFF":$data['color'];
	$bimage = new Imagick();
	$bimage->newImage($width,$height,new ImagickPixel("#".$color));
	$bimage->compositeImage($image,Imagick::COMPOSITE_DEFAULT,$x,$y);
	return $bimage;
}


