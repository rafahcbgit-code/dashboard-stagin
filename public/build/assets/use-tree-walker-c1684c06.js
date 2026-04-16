<<<<<<<< HEAD:public/build/assets/use-tree-walker-c1684c06.js
import{g as f}from"./keyboard-026ab2f8.js";import{M as d}from"./app-93e86eb1.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
========
import{g as f}from"./keyboard-08c784b6.js";import{M as d}from"./app-f84c7462.js";function N({container:e,accept:r,walk:i,enabled:t}){d(()=>{let a=e.value;if(!a||t!==void 0&&!t.value)return;let c=f(e);if(!c)return;let n=Object.assign(l=>r(l),{acceptNode:r}),o=c.createTreeWalker(a,NodeFilter.SHOW_ELEMENT,n,!1);for(;o.nextNode();)i(o.currentNode)})}export{N as i};
>>>>>>>> 99cccc055067f7aae9305646be643d7d54a988b4:public/build/assets/use-tree-walker-12d794b3.js
