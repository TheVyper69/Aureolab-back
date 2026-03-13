import { api } from './api.js';

class OrdersService {
  async list(page = 1, perPage = 15) {
    return (await api.get(`/orders?page=${encodeURIComponent(page)}&per_page=${encodeURIComponent(perPage)}`)).data;
  }

  async create(payload) {
    return (await api.post('/orders', payload)).data;
  }

  async update(id, payload) {
    return (await api.patch(`/orders/${id}`, payload)).data;
  }

  async patch(id, payload) {
    return (await api.patch(`/orders/${id}`, payload)).data;
  }

  async show(id) {
    return (await api.get(`/orders/${id}`)).data;
  }

  async cancel(id) {
    return (await api.patch(`/orders/${id}/cancel`)).data;
  }
}

export const ordersService = new OrdersService();