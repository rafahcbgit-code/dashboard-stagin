<<<<<<<< HEAD:public/build/assets/use-tree-walker-3871d806.js
import{g as f}from"./keyboard-3111460d.js";import{M as d}from"./app-60ffbfaa.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
========
import{g as f}from"./keyboard-5b624855.js";import{M as d}from"./app-9e51102a.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
>>>>>>>> e05b385391a106ccf18bf832560db5a6f855b5db:public/build/assets/use-tree-walker-5badf926.js
