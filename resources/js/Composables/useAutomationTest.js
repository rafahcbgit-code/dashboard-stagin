import { ref, computed, nextTick, onMounted } from 'vue'
import axios from 'axios'

export function useAutomationTest(props) {
  const isNewContact = ref(false)
  const duringWorkingHours = ref(true)
  const showDebug = ref(true)
  const inputMessage = ref('')
  const isTyping = ref(false)
  const chatMessages = ref([])
  const debugEntries = ref([])
  const flowSessionId = ref(null)
  const activeInputHint = ref(null)

  // Structured debug data
  const triggerTraces = ref([])
  const flowSteps = ref([])
  const aiDecisions = ref([])
  const variables = ref([])
  const botSettings = ref({})
  const sessionInfo = ref(null)
  const unmatchedMessageCount = ref(0)
  const workingHours = ref(null)

  // Session-based overrides (in-memory only, never persisted)
  const triggers = ref([])
  const settingOverrides = ref({})
  const triggerOverrides = ref({})
  const dbBotSettings = ref({})

  const effectiveBotSettings = computed(() => ({
    ...botSettings.value,
    ...settingOverrides.value,
  }))

  const hasOverrides = computed(() => {
    return Object.keys(settingOverrides.value).length > 0
      || Object.keys(triggerOverrides.value).length > 0
  })

  const overrideCount = computed(() => {
    return Object.keys(settingOverrides.value).length
      + Object.keys(triggerOverrides.value).length
  })

  const setSettingOverride = (key, value) => {
    if (dbBotSettings.value[key] === value) {
      const { [key]: _, ...rest } = settingOverrides.value
      settingOverrides.value = rest
    } else {
      settingOverrides.value = { ...settingOverrides.value, [key]: value }
    }
  }

  const setTriggerOverride = (triggerId, enabled) => {
    const trigger = triggers.value.find(t => t.id === triggerId)
    const dbEnabled = trigger?.is_enabled ?? false
    if (dbEnabled === enabled) {
      const { [triggerId]: _, ...rest } = triggerOverrides.value
      triggerOverrides.value = rest
    } else {
      triggerOverrides.value = { ...triggerOverrides.value, [triggerId]: enabled }
    }
  }

  const resetOverrides = () => {
    settingOverrides.value = {}
    triggerOverrides.value = {}
  }

  // Load context on mount
  onMounted(async () => {
    try {
      const response = await axios.get('/automation/test/context')
      const data = response.data
      if (data.bot_settings) {
        botSettings.value = data.bot_settings
        dbBotSettings.value = { ...data.bot_settings }
      }
      if (data.triggers) {
        triggers.value = data.triggers
      }
      if (data.working_hours?.schedule) {
        workingHours.value = data.working_hours.schedule
      }
    } catch (error) {
      // Silently fail — context is for debug panel enrichment, not critical
    }
  })

  const scrollToBottom = () => {
    nextTick(() => {
      const chatArea = document.querySelector('[data-chat-area]')
      if (chatArea) {
        chatArea.scrollTop = chatArea.scrollHeight
      }
    })
  }

  const now = () => {
    const d = new Date()
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0')
  }

  const addBotMessage = (whatsapp, options = null) => {
    chatMessages.value.push({
      sender: 'bot',
      whatsapp,
      time: now(),
      options,
    })
    scrollToBottom()
  }

  const addUserMessage = (text) => {
    chatMessages.value.push({
      sender: 'user',
      text,
      time: now(),
    })
    scrollToBottom()
  }

  const addSystemMessage = (text) => {
    chatMessages.value.push({
      sender: 'system',
      text,
      time: now(),
    })
    scrollToBottom()
  }

  const addDebug = (type, title, detail = '') => {
    debugEntries.value.push({ type, title, detail })
  }

  const resetChat = () => {
    chatMessages.value = []
    debugEntries.value = []
    triggerTraces.value = []
    flowSteps.value = []
    aiDecisions.value = []
    variables.value = []
    flowSessionId.value = null
    inputMessage.value = ''
    activeInputHint.value = null
    unmatchedMessageCount.value = 0
    resetOverrides()
  }

  const sendMessage = async () => {
    const msg = inputMessage.value.trim()
    if (!msg || isTyping.value) return

    addUserMessage(msg)
    inputMessage.value = ''

    if (flowSessionId.value) {
      await advanceFlow(msg)
      return
    }

    await evaluateAndStart(msg)
  }

  const evaluateAndStart = async (message) => {
    isTyping.value = true
    addDebug('info', 'Evaluating triggers...', `Message: "${message}"`)

    try {
      const response = await axios.post('/automation/test/trigger', {
        message,
        is_new_contact: isNewContact.value,
        during_working_hours: duringWorkingHours.value,
        setting_overrides: { ...settingOverrides.value },
        trigger_overrides: { ...triggerOverrides.value },
      })
      const data = response.data

      isTyping.value = false

      // Update structured data
      if (data.trace) {
        triggerTraces.value = data.trace
      }
      if (data.bot_settings) {
        botSettings.value = data.bot_settings
      }

      if (data.working_hours_overridden) {
        addDebug('info', 'Working hours overridden', `Simulating: ${duringWorkingHours.value ? 'During working hours' : 'Outside working hours'}`)
      }

      if (data.whatsapp) {
        // Only show bot messages that are meaningful to the customer experience.
        // Technical/debug messages should only appear in the debug panel.
        const whatsappBody = data.whatsapp?.body || ''
        const isTechnicalMessage = whatsappBody.includes('No trigger matched')
          || whatsappBody.includes('Trigger matched:')
          || whatsappBody.includes('This would be escalated to a human agent')

        if (whatsappBody.includes('Automation is disabled')) {
          addSystemMessage('Automation is disabled — no triggers or AI will respond to messages.')
          addDebug('warning', 'Automation disabled', 'All auto-reply skipped (automation_enabled = false)')
        } else if (!isTechnicalMessage) {
          addBotMessage(data.whatsapp)
        }
      }

      if (data.matched && data.flow) {
        addDebug('success', `Trigger: "${data.trigger.name}"`, `${data.trigger.type}${data.trigger.matched_keyword ? ' · Keyword: ' + data.trigger.matched_keyword : ''}`)
        addDebug('info', `Starting flow: "${data.flow.name}"`)
        unmatchedMessageCount.value = 0

        // Update session info
        sessionInfo.value = {
          flowSessionId: null,
          currentFlow: data.flow.name,
          messagesCount: chatMessages.value.length,
        }

        await startFlow(data.flow.id)
      } else if (data.matched) {
        addDebug('success', `Trigger: "${data.trigger.name}"`, data.trigger.type)
        addDebug('warning', 'No flow linked to this trigger')
      } else {
        addDebug('warning', 'No trigger matched')

        if (data.ai_result) {
          if (data.ai_result.type === 'answer') {
            // Show AI answer in chat regardless of confidence
            if (data.ai_result.below_threshold) {
              // Below threshold — show answer in chat with a note, log in debug
              addDebug('warning', `AI answer below threshold (${data.ai_result.confidence}%)`, data.ai_result.content)
              aiDecisions.value.push({
                type: 'low_confidence',
                content: data.ai_result.content,
                confidence: data.ai_result.confidence,
              })
              // AI still answered — reset unmatched counter
              unmatchedMessageCount.value = 0
            } else {
              addDebug('success', 'AI answered', data.ai_result.content)
              aiDecisions.value.push({
                type: 'answer',
                content: data.ai_result.content,
                confidence: data.ai_result.confidence,
              })
              unmatchedMessageCount.value = 0
            }
          } else if (data.ai_result.type === 'escalation') {
            addDebug('warning', 'Escalation triggered')
            aiDecisions.value.push({
              type: 'escalation',
            })
            addSystemMessage('This conversation would be transferred to a human agent.')
            flowSessionId.value = null
            unmatchedMessageCount.value = 0
          }
        } else {
          // No trigger matched AND no AI answer — this is a true unmatched message
          unmatchedMessageCount.value++

          // Show fallback threshold info in debug only
          const threshold = data.bot_settings?.fallback_threshold || 3
          if (unmatchedMessageCount.value < threshold) {
            addDebug('info', `Fallback threshold: ${unmatchedMessageCount.value}/${threshold} unanswered messages`)
          } else {
            addDebug('warning', `Fallback threshold reached: ${unmatchedMessageCount.value}/${threshold} — would auto-escalate`)
            // Show a clear system message in chat that escalation would happen
            addSystemMessage(`No match found after ${threshold} messages — this conversation would be escalated to a human agent.`)
          }
        }
      }

      if (data.trace) {
        data.trace.forEach(t => {
          addDebug(t.matched ? 'success' : 'info', `${t.name} (${t.type})`, t.reason)
        })
      }
    } catch (error) {
      isTyping.value = false
      addBotMessage({ type: 'text', body: 'Error evaluating triggers. Please try again.' })
      addDebug('error', 'Request failed', error.response?.data?.message || error.message)
    }
  }

  const startFlow = async (flowId) => {
    isTyping.value = true
    addDebug('info', 'Loading flow...')

    try {
      const response = await axios.post('/automation/test/flow/start', { flow_id: flowId })
      const data = response.data

      isTyping.value = false

      if (!data.success) {
        if (data.message && data.message.includes('expired')) {
          addBotMessage({ type: 'text', body: 'Session expired. Please start a new test.' })
          addDebug('error', 'Session expired', 'The flow simulation session has expired.')
          flowSessionId.value = null
          return
        }
        addBotMessage({ type: 'text', body: data.message || 'Failed to start flow.' })
        addDebug('error', 'Failed', data.message)
        return
      }

      flowSessionId.value = data.session_id
      addDebug('success', `Flow: "${data.flow.name}"`)

      // Update session info
      sessionInfo.value = {
        flowSessionId: data.session_id,
        currentFlow: data.flow.name,
        messagesCount: chatMessages.value.length,
      }

      processFlowMessages(data.messages || [])

      if (data.needs_input) {
        addDebug('warning', `Waiting for ${data.needs_input.node_type} input`)
        activeInputHint.value = data.needs_input.input_type || null
      } else {
        activeInputHint.value = null
      }
    } catch (error) {
      isTyping.value = false
      addBotMessage({ type: 'text', body: 'Error starting flow.' })
      addDebug('error', 'Request failed', error.response?.data?.message || error.message)
    }
  }

  const advanceFlow = async (userInput) => {
    if (!flowSessionId.value) return

    isTyping.value = true
    addDebug('info', `User: "${userInput}"`)

    try {
      const response = await axios.post('/automation/test/flow/step', {
        session_id: flowSessionId.value,
        user_input: userInput,
      })
      const data = response.data

      isTyping.value = false

      if (!data.success) {
        addBotMessage({ type: 'text', body: data.message || 'Error.' })
        addDebug('error', 'Error', data.message)
        flowSessionId.value = null
        activeInputHint.value = null
        return
      }

      const botMessages = (data.messages || []).filter(m => m.node_type !== 'user_message')
      processFlowMessages(botMessages)

      // Update session info
      if (sessionInfo.value) {
        sessionInfo.value.messagesCount = chatMessages.value.length
      }

      if (data.needs_input) {
        addDebug('warning', `Waiting for ${data.needs_input.node_type} input`)
        activeInputHint.value = data.needs_input.input_type || null
      } else if (botMessages.some(m => m.category === 'flow_ended')) {
        flowSessionId.value = null
        activeInputHint.value = null
        addDebug('info', 'Flow complete')
      } else {
        activeInputHint.value = null
      }
    } catch (error) {
      isTyping.value = false
      addBotMessage({ type: 'text', body: 'Error processing input.' })
      addDebug('error', 'Request failed', error.response?.data?.message || error.message)
    }
  }

  const selectOption = async (msg, option) => {
    const idx = chatMessages.value.indexOf(msg)
    if (idx !== -1) {
      chatMessages.value[idx].options = null
    }

    addUserMessage(option.title)
    isTyping.value = true
    addDebug('info', `Selected: "${option.title}"`)

    try {
      const response = await axios.post('/automation/test/flow/step', {
        session_id: flowSessionId.value,
        selected_option: option.id,
      })
      const data = response.data

      isTyping.value = false

      if (!data.success) {
        addBotMessage({ type: 'text', body: data.message || 'Error.' })
        addDebug('error', 'Error', data.message)
        flowSessionId.value = null
        return
      }

      const botMessages = (data.messages || []).filter(m => m.node_type !== 'user_message')
      processFlowMessages(botMessages)

      if (data.needs_input) {
        addDebug('warning', `Waiting for ${data.needs_input.node_type} input`)
        activeInputHint.value = data.needs_input.input_type || null
      } else if (botMessages.some(m => m.category === 'flow_ended')) {
        flowSessionId.value = null
        activeInputHint.value = null
        addDebug('info', 'Flow complete')
      } else {
        activeInputHint.value = null
      }
    } catch (error) {
      isTyping.value = false
      addBotMessage({ type: 'text', body: 'Error.' })
      addDebug('error', 'Request failed', error.response?.data?.message || error.message)
    }
  }

  const testAiDirectly = async () => {
    const msg = inputMessage.value.trim()
    if (!msg || isTyping.value) return

    addUserMessage(msg)
    inputMessage.value = ''
    isTyping.value = true

    addDebug('info', 'Testing AI directly...', `Message: "${msg}"`)

    try {
      const response = await axios.post('/automation/test/ai', {
        message: msg,
        setting_overrides: { ...settingOverrides.value },
      })
      const data = response.data

      isTyping.value = false

      if (data.whatsapp) {
        addBotMessage(data.whatsapp)
      }

      if (data.type === 'answer') {
        addDebug('success', 'AI answered', data.answer || data.message)
        aiDecisions.value.push({
          type: 'answer',
          content: data.answer || data.message,
          confidence: data.confidence_threshold,
          kb_source: data.kb_source,
        })
      } else if (data.type === 'escalation') {
        addDebug('warning', 'AI escalated to human')
        aiDecisions.value.push({
          type: 'escalation',
          content: data.message,
          escalation_name: data.escalation?.name,
          escalation_target: data.escalation?.target_type,
        })
      } else if (data.type === 'no_answer') {
        addDebug('warning', 'AI could not find an answer')
        aiDecisions.value.push({
          type: 'no_answer',
          content: data.message,
        })
      } else if (!data.success) {
        addDebug('error', 'AI test failed', data.message)
      }
    } catch (error) {
      isTyping.value = false
      addBotMessage({ type: 'text', body: 'Error testing AI.' })
      addDebug('error', 'Request failed', error.response?.data?.message || error.message)
    }
  }

  const processFlowMessages = (messages) => {
    let hasHumanTransfer = false

    messages.forEach(msg => {
      if (msg.node_type === 'start') {
        addDebug('info', 'Flow started')
        flowSteps.value.push({
          node_type: msg.node_type,
          category: msg.category,
          description: msg.description || 'Flow started',
        })
        return
      }

      // Add to flow steps
      flowSteps.value.push({
        node_type: msg.node_type,
        category: msg.category,
        description: msg.description,
        condition_result: msg.condition_result || null,
        variables_set: msg.variables_set || null,
        validation_rules: msg.validation_rules || null,
      })

      // Detect human transfer — show a clear indicator and end session
      if (msg.whatsapp?.type === 'human_transfer' || msg.node_type === 'assign_to_agent') {
        hasHumanTransfer = true
      }

      // Technical/debug messages should only appear in the debug panel, not in chat
      const isTechnicalFlowMessage = msg.category === 'flow_ended'
        && (msg.node_type === 'end' || msg.node_type === 'loop' || msg.node_type === 'unknown')

      if (msg.whatsapp && !isTechnicalFlowMessage) {
        const options = msg.options || null
        addBotMessage(msg.whatsapp, options)
      }

      if (msg.category === 'action_executed') {
        addDebug('info', msg.description || `Action: ${msg.node_type}`)
      } else if (msg.category === 'waiting_for_input') {
        addDebug('warning', msg.description || 'Waiting for input')
      } else if (msg.category === 'flow_redirect') {
        addDebug('info', msg.description || 'Redirecting')
      } else if (msg.category === 'flow_ended') {
        addDebug('info', msg.description || 'Flow ended')
      }
    })

    // After processing all messages, if human transfer happened,
    // show a clear indicator and reset the flow session
    if (hasHumanTransfer) {
      addSystemMessage('Conversation handed off to a human agent — the bot is no longer responding.')
      flowSessionId.value = null
      addDebug('info', 'Session ended — conversation transferred to human agent')
    }
  }

  return {
    isNewContact,
    duringWorkingHours,
    showDebug,
    inputMessage,
    isTyping,
    chatMessages,
    debugEntries,
    flowSessionId,
    // Structured debug data
    triggerTraces,
    flowSteps,
    aiDecisions,
    variables,
    botSettings,
    sessionInfo,
    unmatchedMessageCount,
    activeInputHint,
    workingHours,
    // Override state
    triggers,
    settingOverrides,
    triggerOverrides,
    dbBotSettings,
    effectiveBotSettings,
    hasOverrides,
    overrideCount,
    // Methods
    sendMessage,
    evaluateAndStart,
    startFlow,
    advanceFlow,
    selectOption,
    testAiDirectly,
    addBotMessage,
    addUserMessage,
    addSystemMessage,
    addDebug,
    resetChat,
    scrollToBottom,
    setSettingOverride,
    setTriggerOverride,
    resetOverrides,
  }
}