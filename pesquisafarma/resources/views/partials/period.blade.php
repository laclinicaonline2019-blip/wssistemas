<div class="row auto"><div><label>De</label><input type="date" name="de" value="{{ request('de', now()->startOfMonth()->toDateString()) }}"></div>
<div><label>Até</label><input type="date" name="ate" value="{{ request('ate', now()->toDateString()) }}"></div></div>
