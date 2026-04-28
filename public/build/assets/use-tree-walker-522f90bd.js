<<<<<<<< HEAD:public/build/assets/use-tree-walker-522f90bd.js
import{g as f}from"./keyboard-e582610a.js";import{M as d}from"./app-0c421706.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
========
import{g as f}from"./keyboard-3b761a55.js";import{M as d}from"./app-9753bd4f.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
>>>>>>>> 2a02b3dd809c6ff2d90b1d779b18efcc59f853c2:public/build/assets/use-tree-walker-879a4e10.js
