(function(){
  const carousel=document.getElementById('hero-feature-carousel');
  const shell=document.getElementById('hero-video-shell');
  const target=document.getElementById('hero-latest-video');
  if(!carousel||!shell||!target)return;
  let player=null,playing=false,visible=true,floatingDisabled=false;
  const updateFloat=()=>shell.classList.toggle('is-floating',playing&&!visible&&!floatingDisabled);
  new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;updateFloat();},{threshold:.18}).observe(carousel);
  document.getElementById('hero-video-close')?.addEventListener('click',()=>{floatingDisabled=true;shell.classList.remove('is-floating');player?.pauseVideo();});
  carousel.addEventListener('slide.bs.carousel',event=>{if(event.relatedTarget?.dataset.featureType!=='video'&&playing)player?.pauseVideo();});
  const carouselInstance=bootstrap.Carousel.getOrCreateInstance(carousel);
  const createPlayer=()=>{if(!window.YT?.Player)return;player=new YT.Player(target,{videoId:target.dataset.videoId,playerVars:{autoplay:1,mute:1,playsinline:1,rel:0,modestbranding:1},events:{onReady:event=>{event.target.mute();event.target.playVideo();},onStateChange:event=>{playing=event.data===YT.PlayerState.PLAYING;if(playing){floatingDisabled=false;carouselInstance.pause();}else if(event.data!==YT.PlayerState.BUFFERING)carouselInstance.cycle();updateFloat();}}});};
  const previousReady=window.onYouTubeIframeAPIReady;
  window.onYouTubeIframeAPIReady=()=>{if(typeof previousReady==='function')previousReady();createPlayer();};
  if(window.YT?.Player)createPlayer();else if(!document.querySelector('script[src*="youtube.com/iframe_api"]')){const api=document.createElement('script');api.src='https://www.youtube.com/iframe_api';document.head.append(api);}
})();
