/* site-icons-v1 */
(()=>{
const paths={'↗':'M5 19 19 5M5 5h14v14','→':'M4 12h16m-6-6 6 6-6 6','←':'M20 12H4m6-6-6 6 6 6','↘':'M5 5l14 14M5 19h14V5','↖':'M19 19 5 5M5 19V5h14','↙':'M19 5 5 19M5 5v14h14'};
const convert=root=>{
 const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode(node){return node.parentElement&&!node.parentElement.closest('script,style,textarea,input,svg,select,option,[contenteditable]')&&/[↗→←↘↖↙]/u.test(node.nodeValue)?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;}});
 const nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);
 nodes.forEach(node=>{
  const fragment=document.createDocumentFragment();node.nodeValue.split(/([↗→←↘↖↙][\uFE0E\uFE0F]?)/u).forEach(text=>{
   const path=paths[text[0]];if(!path){fragment.append(document.createTextNode(text));return;}
   const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('class','site-line-arrow');svg.setAttribute('width','18');svg.setAttribute('height','18');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.8');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');const p=document.createElementNS(svg.namespaceURI,'path');p.setAttribute('d',path);svg.append(p);fragment.append(svg);
  });node.replaceWith(fragment);
 });
};
convert(document.body);
})();
