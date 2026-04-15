// KO – GF Choice Rules frontend (embedded-only, VALUE-based) v2.5.2
(function () {
  const qs  = (r, s) => (r || document).querySelector(s);
  const qsa = (r, s) => Array.from((r || document).querySelectorAll(s));
  const LOG = false; // set true to see debug logs

  const log = (...a)=>{ if(LOG) console.log('[KO-GF]', ...a); };

  function getScriptEl(){ return qsa(document,'script').find(s => (s.src||'').includes('ko-gf-lock-frontend.js')); }
  function getGFForms(){ return qsa(document,'.gform_wrapper form[id^="gform_"]'); }
  function getFormId(f){ const m=(f.id||'').match(/^gform_(\d+)$/); return m?parseInt(m[1],10):0; }
  function getTriggerInputs(f,id){ return qsa(f,`input[name="input_${id}"]`); }
  function getTriggerValue(f,id){ const sel=qsa(f,`input[name="input_${id}"]:checked`); return sel.length?sel[0].value:''; }
  function getTargetField(f,fid,id){ return qs(f,`#field_${fid}_${id}`); }
  function getChoiceBlocks(tf){ if(!tf)return[]; let w=qs(tf,'.gfield_radio'); if(!w)return[]; let b=qsa(w,'.gchoice'); if(!b.length)b=qsa(w,'li'); return b; }
  const baseVal = v => String(v||'').split('|')[0].trim();

  function applyRuleToForm(rule, form){
    const fid=getFormId(form); if(fid!==parseInt(rule.form_id,10)) return;
    const trigVal=getTriggerValue(form,rule.trigger_field);
    const target=getTargetField(form,fid,rule.target_field); if(!target) return;
    const blocks=getChoiceBlocks(target); if(!blocks.length) return;

    const triggerMatches=(String(trigVal).toLowerCase().trim()===String(rule.trigger_value).toLowerCase().trim());
    const shouldAct=(rule.logic_mode==='when_trigger_match')?triggerMatches:!triggerMatches;
    const wanted=baseVal(rule.target_value);

    blocks.forEach(el=>{
      const input=qs(el,'input[type="radio"]'); if(!input) return;
      const isTarget = wanted!=='' && baseVal(input.value)===wanted;
      if(!isTarget) return;

      if(shouldAct){
        if(rule.action==='disable'){
          el.style.pointerEvents='none'; el.style.opacity='0.5';
          if(input.checked){ input.checked=false; input.dispatchEvent(new Event('change',{bubbles:true})); }
          input.disabled=true; input.setAttribute('aria-disabled','true');
          log('disabled', {fid, field:rule.target_field, value:input.value});
        }else{
          el.style.display='none';
          if(input.checked){ input.checked=false; input.dispatchEvent(new Event('change',{bubbles:true})); }
          log('hid', {fid, field:rule.target_field, value:input.value});
        }
      }else{
        el.style.display=''; el.style.pointerEvents=''; el.style.opacity='';
        input.disabled=false; input.removeAttribute('aria-disabled'); input.removeAttribute('tabindex');
        log('reverted', {fid, field:rule.target_field, value:input.value});
      }
    });
  }

  function applyAllRules(rules){
    const forms=getGFForms();
    forms.forEach(form=>{
      rules.forEach(r=>applyRuleToForm(r,form));

      if(!form.__ko_lock_wired){
        const handled=new Set();
        rules.filter(r=>getFormId(form)===parseInt(r.form_id,10)).forEach(r=>{
          if(handled.has(r.trigger_field)) return; handled.add(r.trigger_field);
          getTriggerInputs(form,r.trigger_field).forEach(inp=>{
            inp.addEventListener('change',()=>rules.forEach(rr=>applyRuleToForm(rr,form)));
          });
        });

        // Redraws inside the form (GF multi-page, conditional logic DOM swaps)
        if('MutationObserver' in window){
          const obs=new MutationObserver(()=>rules.forEach(r=>applyRuleToForm(r,form)));
          obs.observe(form,{childList:true,subtree:true});
        }
        form.__ko_lock_wired=true;
      }
    });
  }

  function bootOnce(rules){
    if(!Array.isArray(rules)||!rules.length){ log('no rules'); return; }

    // POLL UNTIL trigger & target fields exist (handles late-render)
    const deadline = Date.now()+4000; // up to 4s
    (function waitAndApply(){
      const ready = rules.every(r=>{
        const form = qs(document, `#gform_${r.form_id}`);
        return form && getTargetField(form, r.form_id, r.target_field);
      });
      if(ready){
        applyAllRules(rules);
      }else if(Date.now()<deadline){
        setTimeout(waitAndApply, 80);
      }else{
        // Apply anyway; the MutationObserver will catch subsequent inserts
        applyAllRules(rules);
      }
    })();
  }

  function boot(){
    const s=getScriptEl(); if(!s) return;
    const data=s.getAttribute('data-ko-rules'); if(!data){ log('no data-ko-rules'); return; }
    let rules; try{ rules=JSON.parse(data); } catch(e){ console.error('[KO GF Choice Rules] Bad rules JSON', e); return; }
    bootOnce(rules);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // Debounced page-level observer for late-loaded forms
  let t; function schedule(){ clearTimeout(t); t=setTimeout(boot, 60); }
  if('MutationObserver' in window){ new MutationObserver(schedule).observe(document.documentElement,{childList:true,subtree:true}); }
})();