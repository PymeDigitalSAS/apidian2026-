// logger.js
const EventEmitter = require('events');
const logger = new EventEmitter();

logger.on('log', msg => {
  console.log(msg); // Este es el truco: redirige todo a stdout
});

module.exports = logger;
