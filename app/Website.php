<?php
declare(strict_types=1);

function html_escape(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

function load_html(string $html): DOMDocument
{
    $previous=libxml_use_internal_errors(true);$document=new DOMDocument('1.0','UTF-8');
    $document->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
    foreach(iterator_to_array($document->childNodes) as $node)if($node instanceof DOMProcessingInstruction)$document->removeChild($node);
    return $document;
}

function replace_node_html(DOMDocument $document,DOMNode $node,string $html): void
{
    $fragment=load_html('<div id="fragment">'.$html.'</div>');$xp=new DOMXPath($fragment);$source=$xp->query('//*[@id="fragment"]')->item(0);
    while($node->firstChild)$node->removeChild($node->firstChild);
    foreach(iterator_to_array($source->childNodes) as $child)$node->appendChild($document->importNode($child,true));
}

function page_body_html(string $text): string
{
    $parts=preg_split('/\R\s*\R/',trim($text));$html='';
    foreach($parts as $part){$part=trim($part);if($part==='')continue;
        if(str_starts_with($part,'## '))$html.='<h2>'.html_escape(substr($part,3)).'</h2>';
        elseif(str_starts_with($part,'- ')){$html.='<ul>';foreach(preg_split('/\R/',$part) as $line)$html.='<li>'.html_escape(preg_replace('/^-\s*/','',$line)).'</li>';$html.='</ul>';}
        else $html.='<p>'.nl2br(html_escape($part)).'</p>';
    }
    return $html;
}

function initialize_content(PDO $db): void
{
    initialize_homepage($db);
    if(!setting($db,'faqs_initialized',false)){
        $document=load_html(file_get_contents(PUBLIC_ROOT.'/how-it-works.html'));$xp=new DOMXPath($document);$i=0;
        foreach($xp->query('//details') as $detail){$question=$detail->getElementsByTagName('summary')->item(0)?->textContent??'';$answer=$detail->getElementsByTagName('p')->item(0)?->textContent??'';execute($db,'INSERT IGNORE INTO faqs (id,question,answer,sort_order) VALUES (?,?,?,?)',['faq-'.(++$i),trim($question),trim(preg_replace('/\s+/',' ',$answer)),$i]);}
        set_setting($db,'faqs_initialized',true);
    }
    if(setting($db,'content_initialized',false))return;
    foreach(['home'=>'index.html','services'=>'services.html','how-it-works'=>'how-it-works.html','service-areas'=>'service-areas.html','contact'=>'contact.html','privacy'=>'privacy.html','terms'=>'terms.html'] as $id=>$file){
        $document=load_html(file_get_contents(PUBLIC_ROOT.'/'.$file));$xp=new DOMXPath($document);
        $heading=$xp->query('//main//h1')->item(0);$headingText=$heading?trim(preg_replace('/\s+/',' ',$heading->textContent)):'';
        if($id==='home')$headingText="A clean car.\nA clearer mind.";
        $intro=$xp->query('//main//section[1]//p')->item(0);$description=$xp->query('//meta[@name="description"]')->item(0)?->getAttribute('content')??'';
        $body='';
        if(in_array($id,['privacy','terms'],true))foreach($xp->query('//main//article/*') as $child){if($child->nodeName==='h2')$body.='## '.trim($child->textContent)."\n\n";elseif($child->nodeName==='p')$body.=trim(preg_replace('/\s+/',' ',$child->textContent))."\n\n";}
        execute($db,'INSERT IGNORE INTO site_content (id,title,meta_title,meta_description,heading,summary,body) VALUES (?,?,?,?,?,?,?)',[$id,ucwords(str_replace('-',' ',$id)),preg_replace('/\s*\|\s*SteamRex$/','',trim($document->getElementsByTagName('title')->item(0)->textContent)),$description,$headingText,$intro?trim(preg_replace('/\s+/',' ',$intro->textContent)):'',$body]);
    }
    set_setting($db,'content_initialized',true);
}

function public_service_cards(PDO $db,bool $photos=false): string
{
    $services=rows($db,'SELECT * FROM services WHERE active = 1 AND deleted_at IS NULL ORDER BY sort_order,name');$html='';
    foreach($services as $index=>$service){
        $icon='<svg viewBox="0 0 90 60" fill="none" aria-hidden="true"><path d="m12 37 6-15h43l13 15h5v12H8V37h4Z" stroke="currentColor" stroke-width="1.6"/><path d="m26 22-5 15h39l-7-15M38 23v14M10 37h65" stroke="currentColor" stroke-width="1.5"/><circle cx="24" cy="48" r="6" stroke="currentColor"/><circle cx="65" cy="48" r="6" stroke="currentColor"/></svg>';
        $photo=$service['image_path']?:match($service['id']){'interior'=>'/assets/images/steamrex-detail.jpg','exterior'=>'/assets/images/steamrex-exterior.jpg',default=>'/assets/images/steamrex-steam.jpg'};
        $visual=$photos?'<div class="service-photo"><img src="'.html_escape($photo).'" alt="'.html_escape($service['name']).' by SteamRex" width="1000" height="667" loading="lazy"></div>':'<div class="service-symbol">'.$icon.'</div>';
        $html.='<article class="service-card'.($index===0?' featured':'').'"><span class="card-number">'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT).' / STEAMREX</span>'.$visual.'<h3>'.html_escape($service['name']).'</h3><p>'.html_escape($service['summary']).'</p><div class="card-tags"><span>Vehicle-specific packages</span></div><a class="text-link" href="/services/'.rawurlencode($service['slug']).'.html">See service &amp; packages <span aria-hidden="true">↗</span></a></article>';
    }
    return $html?:'<p>No services are available right now. Please contact the team.</p>';
}

function website_routes(PDO $db): array
{
    $map=['home'=>'/','services'=>'/services.html','how-it-works'=>'/how-it-works.html','service-areas'=>'/service-areas.html','contact'=>'/contact.html','privacy'=>'/privacy.html','terms'=>'/terms.html'];$routes=[];
    foreach(rows($db,'SELECT id,updated_at FROM site_content WHERE published = 1 AND deleted_at IS NULL') as $page){
        if(in_array($page['id'],['privacy','terms'],true) && !setting($db,'legal_approved',false))continue;
        $routes[]=['path'=>$map[$page['id']]??'/pages/'.rawurlencode($page['id']).'.html','modified'=>$page['updated_at']];
    }
    foreach(rows($db,'SELECT slug FROM services WHERE active = 1 AND deleted_at IS NULL') as $service)$routes[]=['path'=>'/services/'.rawurlencode($service['slug']).'.html','modified'=>null];
    return $routes;
}

function render_website(string $path): void
{
    $map=['/'=>'home','/index.html'=>'home','/services.html'=>'services','/how-it-works.html'=>'how-it-works','/service-areas.html'=>'service-areas','/contact.html'=>'contact','/privacy.html'=>'privacy','/terms.html'=>'terms'];
    $db=database();$service=null;$content=null;$notFound=false;$custom=false;
    if(isset($map[$path])){$key=$map[$path];$content=row($db,'SELECT * FROM site_content WHERE id = ? AND published = 1 AND deleted_at IS NULL',[$key]);$template=$key==='home'?'index.html':$key.'.html';if(!$content)$notFound=true;}
    elseif(preg_match('#^/services/([a-z0-9-]+)\.html$#D',$path,$m)){$service=row($db,'SELECT * FROM services WHERE slug = ? AND active = 1 AND deleted_at IS NULL',[$m[1]]);$template='services/complete.html';if(!$service)$notFound=true;}
    elseif(preg_match('#^/pages/([a-z0-9-]+)\.html$#D',$path,$m)){$content=row($db,'SELECT * FROM site_content WHERE id = ? AND published = 1 AND deleted_at IS NULL',[$m[1]]);$template='privacy.html';$custom=true;if(!$content)$notFound=true;}
    elseif($path==='/book.html'){$template='book.html';$key='book';}
    else{$template='404.html';$notFound=true;}
    if($notFound){$template='404.html';$content=null;$service=null;http_response_code(404);}
    $document=load_html(file_get_contents(PUBLIC_ROOT.'/'.$template));$xp=new DOMXPath($document);
    $name=(string)setting($db,'business_name','SteamRex');$demo=demo_mode();$base=rtrim((string)setting($db,'site_url',''),'/');$nonce=base64_encode(random_bytes(18));
    security_headers($nonce);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');
    $title=$content?$content['meta_title']:($service?$service['name']:((($key??'')==='book')?'Choose your clean':'Page not found'));
    if(!$content && !$service && !$notFound && $path==='/book.html')$title='Choose your clean';
    $description=$content?$content['meta_description']:($service?$service['summary']:'Choose your SteamRex service and request a clean.');
    $document->getElementsByTagName('title')->item(0)->textContent=$title.' | '.$name;
    foreach(['name="description"'=>$description,'property="og:title"'=>$title.' | '.$name,'property="og:description"'=>$description] as $attribute=>$value){$node=$xp->query('//meta[@'.$attribute.']')->item(0);if($node)$node->setAttribute('content',$value);}
    foreach($xp->query('//link[@rel="canonical"]|//meta[@property="og:url"]|//meta[@property="og:image"]|//script[@type="application/ld+json"]') as $node)$node->parentNode->removeChild($node);
    $canonical=$base.($path==='/index.html'?'/':$path);
    $head=$document->getElementsByTagName('head')->item(0);$link=$document->createElement('link');$link->setAttribute('rel','canonical');$link->setAttribute('href',$canonical);$head->appendChild($link);
    foreach(['og:url'=>$canonical,'og:image'=>$base.(string)setting($db,'hero_image','/assets/images/hero-car.jpg')] as $property=>$value){$meta=$document->createElement('meta');$meta->setAttribute('property',$property);$meta->setAttribute('content',$value);$head->appendChild($meta);}
    $indexable=!$notFound && $path!=='/book.html' && !$demo && setting($db,'search_indexing',false) && config('APP_ENV','local')==='production' && (!in_array($key??'',['privacy','terms'],true) || setting($db,'legal_approved',false));
    $robots=$xp->query('//meta[@name="robots"]')->item(0);if($robots)$robots->setAttribute('content',$indexable?'index, follow':'noindex, nofollow');
    if(!$indexable)header('X-Robots-Tag: noindex, nofollow');else header_remove('X-Robots-Tag');
    foreach($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," preview-banner ")]') as $node){if(!$demo)$node->parentNode->removeChild($node);else $node->textContent='DEVELOPMENT PREVIEW — sample prices and coverage. No live appointments.';}
    foreach($xp->query('//a[@class="brand"]') as $node){$node->setAttribute('aria-label',$name.' home');$svg=$node->getElementsByTagName('svg')->item(0);$saved=$svg?$svg->cloneNode(true):null;while($node->firstChild)$node->removeChild($node->firstChild);if($saved)$node->appendChild($saved);$node->appendChild($document->createTextNode($name));}
    $footer=$xp->query('//*[contains(@class,"footer-bottom")]/span[1]')->item(0);if($footer)$footer->textContent='© '.gmdate('Y').' '.$name.'.';
    if($content){
        $h1=$xp->query('//main//h1')->item(0);if($h1){$heading=html_escape($content['heading']);if(($key??'')==='home' && str_contains($content['heading'],"\n")){[$a,$b]=explode("\n",$content['heading'],2);$heading=html_escape($a).'<br><em>'.html_escape($b).'</em>';}else $heading=nl2br($heading);replace_node_html($document,$h1,$heading);}
        $intro=$xp->query('//main//section[1]//p')->item(0);if($intro)$intro->textContent=$content['summary'];
        if($content['body']!=='' || $custom || in_array($key??'',['privacy','terms'],true)){
            $prose=$xp->query('//main//article[contains(@class,"prose")]')->item(0);
            if(!$prose){$section=$document->createElement('section');$section->setAttribute('class','section');$prose=$document->createElement('article');$prose->setAttribute('class','wrap narrow prose');$section->appendChild($prose);$document->getElementById('main')->appendChild($section);}
            replace_node_html($document,$prose,page_body_html($content['body']));
            if(!$custom && in_array($key??'',['privacy','terms'],true) && !setting($db,'legal_approved',false)){
                $notice=$document->createElement('div','Draft notice. The business must review and approve this page before accepting live requests.');$notice->setAttribute('class','notice');$prose->insertBefore($notice,$prose->firstChild);
            }
        }
        if($content['image_path']!==''){$image=$xp->query('//main//img')->item(0);if($image)$image->setAttribute('src',$content['image_path']);}
    }
    if($service){
        $h1=$xp->query('//main//h1')->item(0);if($h1)$h1->textContent=$service['name'];$intro=$xp->query('//main//section[1]//p')->item(0);if($intro)$intro->textContent=$service['summary'];
        $detail=$xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," detail-copy ")]')->item(0);
        if($detail)replace_node_html($document,$detail,'<span class="eyebrow">SteamRex services</span><h2>'.html_escape($service['name']).'</h2>'.page_body_html($service['description']?:$service['summary']).($service['exclusions']?'<h3>Good to know</h3>'.page_body_html($service['exclusions']):'').'<a class="btn" href="/book.html?service='.rawurlencode($service['id']).'">See my packages ↗</a>');
        $image=$xp->query('//main//img')->item(0);if($image && $service['image_path'])$image->setAttribute('src',$service['image_path']);
        // The remaining legacy demo paragraph is replaced by a truthful booking explanation.
        $prose=$xp->query('//main//div[contains(@class,"prose")]')->item(0);if($prose)replace_node_html($document,$prose,'<h2>Choose the attention your car needs.</h2><p>Select your vehicle to compare current package inclusions, prices, duration and eligible add-ons. Your request is reviewed before the appointment is confirmed.</p><a class="text-link" href="/book.html?intent=enquiry">Ask a question →</a>');
    }
    foreach($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," service-grid ")]') as $grid)replace_node_html($document,$grid,public_service_cards($db,($key??'')==='home'));
    if(($key??'')==='home'){
        foreach($xp->query('//*[@data-reviews-grid]') as $grid)replace_node_html($document,$grid,homepage_reviews($db));
        foreach($xp->query('//*[@data-hero-video]') as $video){
            $video->setAttribute('data-src',(string)setting($db,'hero_video','/assets/video/steamrex-spring.mp4'));
            $video->setAttribute('poster',(string)setting($db,'hero_image','/assets/images/hero-car.jpg'));
            $video->setAttribute('data-enabled',setting($db,'hero_video_enabled',true)?'true':'false');
        }
    }
    foreach($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," faq-list ")]') as $list){
        $html='';foreach(rows($db,'SELECT question,answer FROM faqs WHERE active = 1 AND deleted_at IS NULL ORDER BY sort_order,id') as $faq)$html.='<details><summary>'.html_escape($faq['question']).'</summary><p>'.nl2br(html_escape($faq['answer'])).'</p></details>';
        replace_node_html($document,$list,$html);
    }
    foreach($xp->query('//img') as $image){$media=row($db,'SELECT alt_text,width,height FROM media WHERE path = ?',[$image->getAttribute('src')]);if($media){if($media['alt_text']!=='')$image->setAttribute('alt',$media['alt_text']);$image->setAttribute('width',(string)$media['width']);$image->setAttribute('height',(string)$media['height']);}}
    $extraLinks=$xp->query('//*[contains(@class,"footer-grid")]/div[2]/ul')->item(0);
    if($extraLinks)foreach(rows($db,"SELECT id,title FROM site_content WHERE published = 1 AND deleted_at IS NULL AND id NOT IN ('home','services','how-it-works','service-areas','contact','privacy','terms') ORDER BY title") as $page){$li=$document->createElement('li');$a=$document->createElement('a');$a->setAttribute('href','/pages/'.rawurlencode($page['id']).'.html');$a->textContent=$page['title'];$li->appendChild($a);$extraLinks->appendChild($li);}
    $hero=$xp->query('//img[contains(@class,"hero-image")]')->item(0);if($hero){$hero->setAttribute('src',(string)setting($db,'hero_image','/assets/images/hero-car.jpg'));$media=row($db,'SELECT alt_text FROM media WHERE path = ?',[$hero->getAttribute('src')]);$hero->setAttribute('alt',($media['alt_text']??'')?:$name.' mobile car cleaning');}
    foreach($xp->query('//link[@rel="preload" and @as="image"]') as $node)$node->setAttribute('href',(string)setting($db,'hero_image','/assets/images/hero-car.jpg'));
    foreach($xp->query('//a[starts-with(@href,"tel:")]') as $a){$a->setAttribute('href','tel:'.preg_replace('/[^+0-9]/','',(string)setting($db,'phone','')));$a->textContent=(string)setting($db,'phone','');}
    foreach($xp->query('//a[starts-with(@href,"mailto:")]') as $a){$email=str_starts_with($a->getAttribute('href'),'mailto:erhverv')?setting($db,'business_email',''):setting($db,'email','');$a->setAttribute('href','mailto:'.$email);$a->textContent=(string)$email;}
    if(($key??'')==='service-areas'){$intro=$xp->query('//main//section[1]//p')->item(0);if($intro)$intro->textContent='Our service areas: '.setting($db,'service_regions','').'. Check your postcode for current coverage.';}
    if(($key??'')==='contact'){
        $details=$xp->query('//*[contains(@class,"contact-details")]')->item(0);if($details)replace_node_html($document,$details,'<h3>Contact '.html_escape($name).'</h3><p><a class="text-link" href="tel:'.html_escape(preg_replace('/[^+0-9]/','',(string)setting($db,'phone',''))).'">'.html_escape((string)setting($db,'phone','')).'</a></p><p>'.html_escape((string)setting($db,'contact_hours','')).' ('.html_escape((string)setting($db,'timezone','')).')</p><p><a href="mailto:'.html_escape((string)setting($db,'email','')).'">'.html_escape((string)setting($db,'email','')).'</a></p><p><a href="mailto:'.html_escape((string)setting($db,'business_email','')).'">'.html_escape((string)setting($db,'business_email','')).'</a></p>');
    }
    if(!$demo){
        foreach($xp->query('//*[contains(@class,"form-hint") or contains(@class,"notice")]') as $n)if(preg_match('/test checker|local preview|test enquiries locally|development form/i',$n->textContent))$n->parentNode->removeChild($n);
    }
    $graph=[['@type'=>'WebSite','@id'=>$base.'/#website','url'=>$base.'/','name'=>$name],['@type'=>'Organization','@id'=>$base.'/#organization','name'=>$name,'url'=>$base.'/','telephone'=>setting($db,'phone',''),'email'=>setting($db,'email','')]];
    if($service)$graph[]=['@type'=>'Service','name'=>$service['name'],'description'=>$service['summary'],'provider'=>['@id'=>$base.'/#organization'],'url'=>$canonical];
    if($path!=='/' && !$notFound)$graph[]=['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>$base.'/'],['@type'=>'ListItem','position'=>2,'name'=>$service['name']??($content['title']??'Booking'),'item'=>$canonical]]];
    $script=$document->createElement('script');$script->setAttribute('type','application/ld+json');$script->setAttribute('nonce',$nonce);$script->appendChild($document->createTextNode(json_encode(['@context'=>'https://schema.org','@graph'=>$graph],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));$head->appendChild($script);
    echo $document->saveHTML();
}
