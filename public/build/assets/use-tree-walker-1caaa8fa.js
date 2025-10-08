<<<<<<<< HEAD:public/build/assets/use-tree-walker-9762c2f1.js
import{g as f}from"./keyboard-61e7c2dd.js";import{S as d}from"./app-a22831f2.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
========
import{g as f}from"./keyboard-b61a1cbf.js";import{S as d}from"./app-5cdbedeb.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
>>>>>>>> f36c63ac421de9826d571c3e6a2573165098637a:public/build/assets/use-tree-walker-1caaa8fa.js
