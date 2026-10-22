<?php
if(($layout??'app')==='marketing'){
 echo '<footer class="marketing-footer"><a class="brand-lockup" href="'.e(url('index.php')).'"><img src="'.e(url('assets/img/datanix-mark.svg')).'" width="28" height="28" alt=""><span>datanix</span></a><span>Data answers, grounded in your numbers.</span><span>© '.date('Y').' Datanix · <a href="'.e(url('privacy.php')).'">Privasi & AI</a></span></footer>';
 echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script><script src="'.e(url('assets/js/app.js')).'"></script></body></html>';return;
}
if(($layout??'app')==='admin'){
 echo '</div><footer class="admin-footer">Datanix Control Plane <span>•</span> Tindakan admin dicatat dalam audit log.</footer></main></div>';
}else{echo '</div><footer class="app-footer"><span>Angka dari data. Bukan tebakan.</span><span><a href="'.e(url('privacy.php')).'">Privasi</a> <a href="'.e(url('plans.php')).'">Paket</a></span></footer></main></div>';}
echo '<div class="toast-holder" id="toast-holder" aria-live="polite"></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script><script src="'.e(url('assets/js/charts.js')).'"></script><script src="'.e(url('assets/js/app.js')).'"></script></body></html>';
